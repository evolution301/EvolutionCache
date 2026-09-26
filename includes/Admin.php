<?php
/**
 * Admin settings page. Standard Settings API; every action is nonce-checked
 * and capability-gated (manage_options); all output is escaped.
 *
 * UX contract: optimal settings are the DEFAULT and the visible UI. Risky
 * knobs live inside a collapsed "advanced" block with a warning, so a casual
 * admin cannot break the front end by poking around.
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const PAGE = 'feathercache';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );

		// Recompile the drop-in config whenever settings change.
		add_action( 'update_option_' . Options::OPT, array( Dropin::class, 'writeConfig' ), 10, 0 );
		add_action( 'update_option_' . Options::OPT, array( Dropin::class, 'ensureRuntime' ), 20, 0 );

		add_action( 'admin_post_feathercache_flush', array( __CLASS__, 'handleFlush' ) );
		add_action( 'admin_post_feathercache_warm', array( __CLASS__, 'handleWarm' ) );
		add_action( 'admin_post_feathercache_regen_key', array( __CLASS__, 'handleRegenKey' ) );

		// Toolbar shortcut so admins can purge from anywhere. Late priority:
		// register after Imagify & co. so the node lands right beside them.
		add_action( 'admin_bar_menu', array( __CLASS__, 'adminBar' ), 99999999 );

		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FEATHERCACHE_FILE ), array( __CLASS__, 'actionLinks' ) );
	}

	/**
	 * "Clear cache" node in the admin toolbar, right after other plugin
	 * icons (Imagify etc.) in the main toolbar group.
	 */
	public static function adminBar( $bar ): void {
		if ( ! current_user_can( 'manage_options' ) || ! ( $bar instanceof \WP_Admin_Bar ) ) {
			return;
		}
		$bar->add_node( array(
			'id'     => 'feathercache-flush',
			'title'  => '<span class="ab-icon dashicons dashicons-performance" style="top:2px"></span><span class="ab-label">清缓存</span>',
			'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=feathercache_flush' ), 'feathercache_flush' ),
			'meta'   => array(
				'title' => '清空 EvolutionCache 页面缓存',
			),
		) );
	}

	public static function menu(): void {
		add_menu_page(
			'EvolutionCache',
			'EvolutionCache',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-performance',
			81
		);
	}

	public static function register(): void {
		register_setting( 'feathercache', Options::OPT, array(
			'type'              => 'array',
			'sanitize_callback' => array( Options::class, 'sanitize' ),
			'default'           => Options::defaults(),
		) );
	}

	public static function actionLinks( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'feathercache' ) . '</a>'
		);
		return $links;
	}

	public static function handleFlush(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'feathercache' ) );
		}
		check_admin_referer( 'feathercache_flush' );

		CacheStore::flush();
		Dropin::writeConfig();

		// Back to where the admin came from (toolbar button works everywhere);
		// front-end referers go back without the notice arg (keeps the URL cacheable).
		$referer = wp_get_referer();
		if ( $referer && 0 === strpos( $referer, home_url() ) && false === strpos( $referer, '/wp-admin/' ) ) {
			wp_safe_redirect( $referer );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'fc-msg', 'flushed', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handleWarm(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'feathercache' ) );
		}
		check_admin_referer( 'feathercache_warm' );

		wp_schedule_single_event( time() + 5, Warmer::CRON_NOW );
		spawn_cron();

		wp_safe_redirect( add_query_arg( 'fc-msg', 'warming', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Rotate the machine-to-machine AI secret.
	 */
	public static function handleRegenKey(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'feathercache' ) );
		}
		check_admin_referer( 'feathercache_regen_key' );

		$s              = Options::all();
		$s['api_key']   = function_exists( 'wp_generate_password' ) ? wp_generate_password( 48, false, false ) : bin2hex( random_bytes( 24 ) );
		update_option( Options::OPT, Options::sanitize( $s ), false );

		wp_safe_redirect( add_query_arg( 'fc-msg', 'key', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function notices(): void {
		// Action feedback.
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] && isset( $_GET['fc-msg'] ) ) {
			$msg = sanitize_key( wp_unslash( $_GET['fc-msg'] ) );
			if ( 'flushed' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '缓存已清空。', 'feathercache' ) . '</p></div>';
			} elseif ( 'warming' === $msg ) {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( '预热任务已启动，稍后可在状态区查看。', 'feathercache' ) . '</p></div>';
			} elseif ( 'key' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API 密钥已重新生成，旧密钥立即失效。', 'feathercache' ) . '</p></div>';
			}
		}

		// Install errors.
		$err = get_transient( 'feathercache_install_error' );
		if ( 'foreign_dropin' === $err ) {
			echo '<div class="notice notice-error"><p><strong>EvolutionCache：</strong>' . esc_html__( '检测到其他缓存插件占用了 advanced-cache.php，EvolutionCache 未接管页面缓存（其余功能正常）。请先停用冲突插件再激活 EvolutionCache。', 'feathercache' ) . '</p></div>';
		} elseif ( 'dropin_write' === $err ) {
			echo '<div class="notice notice-error"><p><strong>EvolutionCache：</strong>' . esc_html__( '无法写入 wp-content/advanced-cache.php（目录权限不足），页面缓存未启用。', 'feathercache' ) . '</p></div>';
		}

		if ( ! Dropin::isOurs() && ! defined( 'WP_CLI' ) ) {
			$s = Options::all();
			if ( ! empty( $s['page_cache'] ) ) {
				echo '<div class="notice notice-warning"><p><strong>EvolutionCache：</strong>' . esc_html__( '页面缓存 drop-in 未生效（WP_CACHE 未开启或 drop-in 缺失）。请到插件页停用再重新激活 EvolutionCache。', 'feathercache' ) . '</p></div>';
			}
		}
	}

	/**
	 * One recommended preset, shown as the primary (read-only) view. All
	 * tuning knobs live in the collapsed advanced section below it.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s        = Options::all();
		$stats    = CacheStore::stats();
		$lastFlsh = (int) get_option( 'feathercache_last_flush' );
		$lastWarm = (int) get_option( 'feathercache_last_warm' );

		$dropinOk = Dropin::isOurs();
		$wpCache  = defined( 'WP_CACHE' ) && constant( 'WP_CACHE' );

		$on  = '<span style="color:#00a32a;font-weight:600">● 已启用</span>';
		$off = '<span style="color:#787c82;font-weight:600">● 已关闭</span>';
		?>
		<div class="wrap">
			<h1>EvolutionCache <small style="font-weight:400;color:#666">v<?php echo esc_html( FEATHERCACHE_VERSION ); ?></small></h1>

			<div class="notice notice-success" style="margin:14px 0 0">
				<p><strong>已为你启用最佳配置</strong> —— 无需任何设置即可获得最优性能。所有优化均带故障自动回退（出错自动恢复原样输出，不会影响页面显示）。</p>
			</div>

			<h2 class="screen-reader-text">Status</h2>
			<table class="widefat striped" style="max-width:900px;margin-top:12px">
				<tbody>
					<tr><td style="width:220px"><strong>页面缓存状态</strong></td><td>
						<?php if ( $dropinOk && $wpCache ) : ?>
							<span style="color:#00a32a">● 运行中</span>（drop-in 已加载）
						<?php elseif ( Dropin::foreignDropinExists() ) : ?>
							<span style="color:#d63638">● 冲突</span>：其他缓存插件占用了 advanced-cache.php
						<?php else : ?>
							<span style="color:#dba617">● 未生效</span>：请停用再激活插件
						<?php endif; ?>
					</td></tr>
					<tr><td><strong>缓存文件</strong></td><td><?php echo esc_html( number_format_i18n( $stats['files'] ) ); ?> 个 / <?php echo esc_html( size_format( $stats['bytes'] ) ); ?></td></tr>
					<tr><td><strong>上次清空</strong></td><td><?php echo esc_html( $lastFlsh ? date_i18n( 'Y-m-d H:i:s', $lastFlsh ) : '—' ); ?></td></tr>
					<tr><td><strong>上次预热</strong></td><td><?php echo esc_html( $lastWarm ? date_i18n( 'Y-m-d H:i:s', $lastWarm ) : '—' ); ?></td></tr>
					<tr><td><strong>操作</strong></td><td>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=feathercache_flush' ), 'feathercache_flush' ) ); ?>">清空缓存</a>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=feathercache_warm' ), 'feathercache_warm' ) ); ?>">预热缓存</a>
					</td></tr>
				</tbody>
			</table>

			<h2 style="margin-top:22px">当前生效的优化（推荐配置，自动应用）</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><td style="width:260px"><strong>整页缓存</strong><br><span class="description">匿名访客直接读取静态页，1 小时自动刷新，内容更新立即失效</span></td><td><?php echo empty( $s['page_cache'] ) ? $off : $on; ?></td></tr>
					<tr><td><strong>Gzip 预压缩</strong><br><span class="description">缓存页预压缩，命中时零 CPU 开销</span></td><td><?php echo empty( $s['gzip'] ) ? $off : $on; ?></td></tr>
					<tr><td><strong>移动端单独缓存</strong><br><span class="description">手机与桌面分别生成缓存，互不串页</span></td><td><?php echo empty( $s['mobile_cache'] ) ? $off : $on; ?></td></tr>
					<tr><td><strong>CSS 压缩 + 非关键 CSS 延迟</strong><br><span class="description">消除渲染阻塞；出错自动保留原始样式</span></td><td><?php echo ( empty( $s['css_minify'] ) && empty( $s['css_defer'] ) ) ? $off : $on; ?></td></tr>
					<tr><td><strong>JS 交互后执行</strong><br><span class="description">访客首次交互或 4 秒后才加载脚本，PageSpeed 最优；cookie 横幅/验证码/表单/统计脚本自动豁免，立即加载</span></td><td><?php echo 'delay' === $s['js_mode'] ? $on : ( 'defer' === $s['js_mode'] ? '<span style="color:#dba617;font-weight:600">● 延迟解析（defer）</span>' : $off ); ?></td></tr>
					<tr><td><strong>图片懒加载 + LCP 预加载</strong><br><span class="description">首屏主图优先加载，其余图片原生懒加载</span></td><td><?php echo ( empty( $s['img_lazy'] ) && empty( $s['img_lcp_preload'] ) ) ? $off : $on; ?></td></tr>
					<tr><td><strong>超大图片自动压缩</strong><br><span class="description">宽 ≥800px 的本地图自动生成 800w/q80 WebP 副本并替换引用，节省数百 KB</span></td><td><?php echo empty( $s['img_resize'] ) ? $off : $on; ?></td></tr>
					<tr><td><strong>Google Fonts 预连接</strong></td><td><?php echo empty( $s['fonts_preconnect'] ) ? $off : $on; ?></td></tr>
					<tr><td><strong>缓存预热</strong><br><span class="description">每小时按站点 sitemap 自动预生成缓存</span></td><td><?php echo empty( $s['warmer_enabled'] ) ? $off : $on; ?></td></tr>
				</tbody>
			</table>

			<h2>AI 接管（Codex / ZCode / WorkBuddy / Claude Code…）</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><td style="width:220px"><strong>API 密钥</strong></td><td>
						<code style="user-select:all;word-break:break-all"><?php echo esc_html( Options::ensureApiKey() ); ?></code>
						<a class="button button-small" style="margin-left:8px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=feathercache_regen_key' ), 'feathercache_regen_key' ) ); ?>">重新生成</a>
						<p class="description">把此密钥交给你的 AI 工具，配合下方端点即可远程管理插件。泄露后请立即重新生成。</p>
					</td></tr>
					<tr><td><strong>REST 端点</strong></td><td>
						<code>GET <?php echo esc_html( rest_url( 'evolutioncache/v1/status' ) ); ?></code><br>
						<code>POST <?php echo esc_html( rest_url( 'evolutioncache/v1/flush' ) ); ?></code><br>
						<code>POST <?php echo esc_html( rest_url( 'evolutioncache/v1/warm' ) ); ?></code><br>
						<code>GET <?php echo esc_html( rest_url( 'evolutioncache/v1/settings' ) ); ?></code>
						<p class="description">请求头携带 <code>X-EvolutionCache-Key: 密钥</code>。示例：<br><code>curl -H "X-EvolutionCache-Key: 密钥" 端点地址</code></p>
					</td></tr>
					<tr><td><strong>WP-CLI</strong></td><td><code>wp evolutioncache status | flush | warm | config</code></td></tr>
				</tbody>
			</table>

			<details style="max-width:900px;margin-top:20px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 18px">
				<summary style="cursor:pointer;font-weight:600">高级设置（默认即最优 —— 不熟悉每项作用请勿修改）</summary>

				<div class="notice notice-warning" style="margin:12px 0 0">
					<p>以下任何一项改动都可能影响前端显示或缓存正确性。修改前请先在测试环境验证；出问题可在本页恢复默认（清空浏览器缓存后重置表单勾选为推荐值）。</p>
				</div>

				<form method="post" action="options.php">
					<?php settings_fields( 'feathercache' ); ?>

					<h2 class="title" style="margin-top:14px">页面缓存</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">启用整页缓存</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[page_cache]" value="1" <?php checked( ! empty( $s['page_cache'] ) ); ?>> 匿名访客访问直接读取静态 HTML</label>
						</td></tr>
						<tr><th scope="row">缓存有效期（秒）</th><td>
							<input type="number" min="300" max="2592000" class="small-text" name="<?php echo esc_attr( Options::OPT ); ?>[cache_ttl]" value="<?php echo esc_attr( (string) $s['cache_ttl'] ); ?>">
							<p class="description">到期后自动重新生成；内容更新时也会自动清空。</p>
						</td></tr>
						<tr><th scope="row">移动端单独缓存</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[mobile_cache]" value="1" <?php checked( ! empty( $s['mobile_cache'] ) ); ?>> 手机/桌面分别生成缓存（响应式主题可关闭以提高命中率）</label>
						</td></tr>
						<tr><th scope="row">Gzip 预压缩</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[gzip]" value="1" <?php checked( ! empty( $s['gzip'] ) ); ?>> 存储预压缩副本，命中时零 CPU 压缩开销</label>
						</td></tr>
						<tr><th scope="row">缓存查询参数白名单</th><td>
							<textarea name="<?php echo esc_attr( Options::OPT ); ?>[query_whitelist]" rows="4" class="large-text code"><?php echo esc_textarea( $s['query_whitelist'] ); ?></textarea>
							<p class="description">每行一个。白名单之外的查询参数（如 ?add-to-cart=、?orderby=）会使该 URL 不进缓存（动态处理）。utm_* 等追踪参数自动忽略。</p>
						</td></tr>
						<tr><th scope="row">排除 URL</th><td>
							<textarea name="<?php echo esc_attr( Options::OPT ); ?>[exclude_urls]" rows="4" class="large-text code"><?php echo esc_textarea( $s['exclude_urls'] ); ?></textarea>
							<p class="description">每行一条：路径前缀（如 /checkout）或 #正则#（如 #/product/.*#）。</p>
						</td></tr>
					</table>

					<h2 class="title">CSS</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">压缩 CSS</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[css_minify]" value="1" <?php checked( ! empty( $s['css_minify'] ) ); ?>> 本地 CSS 压缩为带内容哈希的缓存副本（引号内容受保护，出错自动回退）</label>
						</td></tr>
						<tr><th scope="row">非关键 CSS 延迟加载</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[css_defer]" value="1" <?php checked( ! empty( $s['css_defer'] ) ); ?>> preload + onload 方式加载，消除渲染阻塞（若首屏出现无样式闪烁，把对应文件加入下方排除名单）</label>
						</td></tr>
						<tr><th scope="row">CSS 排除（保持阻塞加载）</th><td>
							<textarea name="<?php echo esc_attr( Options::OPT ); ?>[css_exclude]" rows="3" class="large-text code" placeholder="theme-main&#10;critical"><?php echo esc_textarea( $s['css_exclude'] ); ?></textarea>
							<p class="description">每行一个 href 关键词片段，命中则保持原来的同步加载。</p>
						</td></tr>
						<tr><th scope="row">内联关键 CSS</th><td>
							<textarea name="<?php echo esc_attr( Options::OPT ); ?>[critical_css]" rows="6" class="large-text code"><?php echo esc_textarea( $s['critical_css'] ); ?></textarea>
							<p class="description">可选。粘贴首屏必需 CSS，将内联到 &lt;head&gt; 顶部。</p>
						</td></tr>
					</table>

					<h2 class="title">JavaScript</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">加载模式</th><td>
							<label><input type="radio" name="<?php echo esc_attr( Options::OPT ); ?>[js_mode]" value="off" <?php checked( 'off', $s['js_mode'] ); ?>> 不处理</label><br>
							<label><input type="radio" name="<?php echo esc_attr( Options::OPT ); ?>[js_mode]" value="defer" <?php checked( 'defer', $s['js_mode'] ); ?>> defer（所有外部脚本延迟解析，保持执行顺序）</label><br>
							<label><input type="radio" name="<?php echo esc_attr( Options::OPT ); ?>[js_mode]" value="delay" <?php checked( 'delay', $s['js_mode'] ); ?>> 交互后执行（推荐：用户首次交互或超时后才注入 JS；有兼容风险时请用下方排除名单）</label>
						</td></tr>
						<tr><th scope="row">延迟排除名单</th><td>
							<textarea name="<?php echo esc_attr( Options::OPT ); ?>[js_delay_exclude]" rows="5" class="large-text code"><?php echo esc_textarea( $s['js_delay_exclude'] ); ?></textarea>
							<p class="description">每行一个 src 关键词片段（如 cookie、turnstile）。命中则立即正常加载，绝不延迟。默认已排除 cookie 横幅、验证码、表单、统计类脚本。</p>
						</td></tr>
						<tr><th scope="row">超时（毫秒）</th><td>
							<input type="number" min="0" max="30000" step="100" class="small-text" name="<?php echo esc_attr( Options::OPT ); ?>[js_delay_timeout]" value="<?php echo esc_attr( (string) $s['js_delay_timeout'] ); ?>">
							<p class="description">无交互时等待多少毫秒后自动注入（0 = 仅交互触发）。</p>
						</td></tr>
					</table>

					<h2 class="title">图片与字体</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">原生懒加载</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[img_lazy]" value="1" <?php checked( ! empty( $s['img_lazy'] ) ); ?>> 为未标注的图片添加 loading="lazy" decoding="async"（追踪像素自动跳过）</label>
						</td></tr>
						<tr><th scope="row">LCP 预加载</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[img_lcp_preload]" value="1" <?php checked( ! empty( $s['img_lcp_preload'] ) ); ?>> 首屏主图添加 fetchpriority="high" 并 preload（已有 srcset 的图片仅设置优先级，避免重复下载）</label>
						</td></tr>
						<tr><th scope="row">超大图片自动压缩</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[img_resize]" value="1" <?php checked( ! empty( $s['img_resize'] ) ); ?>> 宽 ≥800px 的本地图片自动生成 800w / q80 的 WebP 副本并替换引用（首次生成后缓存复用；LCP 主图跳过）</label>
						</td></tr>
						<tr><th scope="row">Google Fonts preconnect</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[fonts_preconnect]" value="1" <?php checked( ! empty( $s['fonts_preconnect'] ) ); ?>> 提前建立字体域连接</label>
						</td></tr>
					</table>

					<h2 class="title">缓存预热</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">启用预热</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[warmer_enabled]" value="1" <?php checked( ! empty( $s['warmer_enabled'] ) ); ?>> 每小时按站点 sitemap 预生成缓存</label>
						</td></tr>
						<tr><th scope="row">每次预热页数</th><td>
							<input type="number" min="0" max="500" class="small-text" name="<?php echo esc_attr( Options::OPT ); ?>[warmer_limit]" value="<?php echo esc_attr( (string) $s['warmer_limit'] ); ?>">
						</td></tr>
						<tr><th scope="row">内容更新时清缓存</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPT ); ?>[purge_on_save]" value="1" <?php checked( ! empty( $s['purge_on_save'] ) ); ?>> 发布/更新内容、商品变动时自动清空缓存</label>
						</td></tr>
					</table>

					<?php submit_button( '保存高级设置' ); ?>
				</form>
			</details>

			<h2>安全说明</h2>
			<p class="description" style="max-width:900px">
				所有缓存文件名均为 SHA-256 哈希（无路径注入面）；管理操作全部校验 nonce 与 manage_options 权限；
				设置项白名单清洗、输出全部转义；预热爬虫仅请求本站自身域名（防 SSRF）；
				缓存目录带 index.php 与 .htaccess 防列举；插件不含任何外部请求、eval、SQL 拼接。
			</p>
		</div>
		<?php
	}
}
