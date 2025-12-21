/**
 * Plugin Name: My AbuseIPDB Dashboard Widget
 * Description: Adds a dashboard widget to check IP reputation via AbuseIPDB API with AJAX.
 * Version: 1.0.0 (Feature/AbuseIPDB Integration)
 * Date: 2025-12-21
 * Author: WP TW Architect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1. 註冊 Dashboard Widget
 */
function tw365_register_abuseipdb_widget() {
	// 僅限管理員權限可見
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'tw365_abuseipdb_widget',           // Widget ID
		'🛡️ My AbuseIPDB Dashboard Widget', // Widget Title
		'tw365_render_abuseipdb_widget'     // Callback
	);
}
add_action( 'wp_dashboard_setup', 'tw365_register_abuseipdb_widget' );

/**
 * 2. 渲染 Widget 內容 (HTML)
 */
function tw365_render_abuseipdb_widget() {
	// 取得 API Key (注意：此 Option 應設為 autoload=no)
	$api_key = get_option( 'tw365_abuseipdb_api_key' );

	?>
	<div class="tw365-abuseipdb-container" style="padding: 10px 0;">
		<?php if ( empty( $api_key ) ) : ?>
			<div id="tw365-apikey-form-wrapper">
				<p>請先輸入您的 AbuseIPDB API Key：</p>
				<form id="tw365-apikey-form">
					<p>
						<input type="password" id="tw365_api_key_input" class="widefat" placeholder="AbuseIPDB API Key" required>
					</p>
					<button type="submit" class="button button-primary">儲存設定</button>
					<span class="spinner" style="float: none; margin-left: 5px;"></span>
				</form>
			</div>
		<?php else : ?>
			<div id="tw365-ip-check-wrapper">
				<form id="tw365-ip-form" style="display: flex; gap: 5px; margin-bottom: 15px;">
					<input type="text" id="tw365_ip_input" class="widefat" placeholder="輸入 IP (例如: 8.8.8.8)" required>
					<button type="submit" class="button button-primary">查詢</button>
				</form>
				
				<div id="tw365-result-area" style="min-height: 50px; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
					<p class="description">請輸入 IP 進行查詢...</p>
				</div>
				
				<hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;">
				
				<button type="button" id="tw365-reset-key-btn" class="button button-small">重設 API Key</button>
			</div>
		<?php endif; ?>

		<div id="tw365-status-msg" style="margin-top: 10px; color: #d63638; font-weight: bold;"></div>
	</div>

	<script type="text/javascript">
	jQuery(document).ready(function($) {
		
		// 共用的處理函數
		function showStatus(msg, isError = false) {
			const color = isError ? '#d63638' : '#00a32a';
			$('#tw365-status-msg').css('color', color).text(msg).show().delay(3000).fadeOut();
		}

		// 1. 儲存 API Key
		$('#tw365-apikey-form').on('submit', function(e) {
			e.preventDefault();
			const key = $('#tw365_api_key_input').val();
			const spinner = $(this).find('.spinner');
			
			spinner.addClass('is-active');

			$.post(ajaxurl, {
				action: 'tw365_save_abuseipdb_key',
				api_key: key,
				nonce: '<?php echo esc_js( wp_create_nonce( 'tw365_abuseipdb_config' ) ); ?>'
			}).done(function(res) {
				spinner.removeClass('is-active');
				if (res.success) {
					showStatus('API Key 已儲存，請重新整理頁面。', false);
					setTimeout(function(){ location.reload(); }, 1000);
				} else {
					showStatus(res.data || '儲存失敗', true);
				}
			}).fail(function() {
				spinner.removeClass('is-active');
				showStatus('連線錯誤', true);
			});
		});

		// 2. 查詢 IP
		$('#tw365-ip-form').on('submit', function(e) {
			e.preventDefault();
			const ip = $('#tw365_ip_input').val();
			const resultArea = $('#tw365-result-area');
			
			resultArea.html('<span class="spinner is-active" style="float:none;"></span> 查詢中...');

			$.post(ajaxurl, {
				action: 'tw365_check_ip_score',
				ip: ip,
				nonce: '<?php echo esc_js( wp_create_nonce( 'tw365_abuseipdb_check' ) ); ?>'
			}).done(function(res) {
				if (res.success) {
					const data = res.data.data; // AbuseIPDB structure
					let html = `
						<p><strong>IP:</strong> ${data.ipAddress}</p>
						<p><strong>所在地:</strong> ${data.countryCode} <img src="https://flagsapi.com/${data.countryCode}/flat/16.png" style="vertical-align:text-bottom;"></p>
						<p><strong>ISP:</strong> ${data.isp}</p>
						<p><strong>濫用評分:</strong> <span style="font-weight:bold; color: ${data.abuseConfidenceScore > 50 ? 'red' : 'green'};">${data.abuseConfidenceScore}%</span></p>
						<p><strong>最後回報:</strong> ${data.lastReportedAt || '無'}</p>
					`;
					resultArea.html(html);
				} else {
					resultArea.html('<span style="color:red;">錯誤: ' + (res.data || '未知錯誤') + '</span>');
				}
			}).fail(function() {
				resultArea.html('<span style="color:red;">連線失敗，請稍後再試。</span>');
			});
		});

		// 3. 重設 API Key
		$('#tw365-reset-key-btn').on('click', function() {
			if(!confirm('確定要刪除現有的 API Key 嗎？')) return;
			
			$.post(ajaxurl, {
				action: 'tw365_reset_abuseipdb_key',
				nonce: '<?php echo esc_js( wp_create_nonce( 'tw365_abuseipdb_config' ) ); ?>'
			}).done(function(res) {
				if(res.success) location.reload();
			});
		});
	});
	</script>
	<?php
}

/**
 * 3. AJAX Handler: 儲存 API Key
 */
function tw365_ajax_save_abuseipdb_key() {
	check_ajax_referer( 'tw365_abuseipdb_config', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '權限不足' );
	}

	if ( empty( $_POST['api_key'] ) ) {
		wp_send_json_error( '請輸入 API Key' );
	}

	$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );

	// 重要優化：設定 autoload 為 no
	// 先檢查是否存在，若不存在則新增並指定 autoload=no
	if ( false === get_option( 'tw365_abuseipdb_api_key' ) ) {
		add_option( 'tw365_abuseipdb_api_key', $api_key, '', 'no' );
	} else {
		update_option( 'tw365_abuseipdb_api_key', $api_key );
		// 確保 WP 6.4+ 之前的版本也能處理 autoload (若原本是 yes)
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_tw365_save_abuseipdb_key', 'tw365_ajax_save_abuseipdb_key' );

/**
 * 4. AJAX Handler: 重設 API Key
 */
function tw365_ajax_reset_abuseipdb_key() {
	check_ajax_referer( 'tw365_abuseipdb_config', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '權限不足' );
	}
	delete_option( 'tw365_abuseipdb_api_key' );
	wp_send_json_success();
}
add_action( 'wp_ajax_tw365_reset_abuseipdb_key', 'tw365_ajax_reset_abuseipdb_key' );

/**
 * 5. AJAX Handler: 查詢 IP (核心邏輯)
 */
function tw365_ajax_check_ip_score() {
	// 安全性：Nonce 驗證
	check_ajax_referer( 'tw365_abuseipdb_check', 'nonce' );

	// 安全性：權限驗證
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '權限不足' );
	}

	// 輸入驗證
	$raw_ip = isset( $_POST['ip'] ) ? wp_unslash( $_POST['ip'] ) : '';
	$ip     = filter_var( $raw_ip, FILTER_VALIDATE_IP );

	if ( ! $ip ) {
		wp_send_json_error( '無效的 IP 格式' );
	}

	$api_key = get_option( 'tw365_abuseipdb_api_key' );
	if ( ! $api_key ) {
		wp_send_json_error( 'API Key 尚未設定' );
	}

	// 快取機制：檢查 Transient
	// Key 格式: tw365_abuseipdb_{IP_ADDRESS}
	// 使用 md5 雜湊 IP 可以避免 Key 包含特殊字元
	$cache_key = 'tw365_abuse_' . md5( $ip );
	$cached_data = get_transient( $cache_key );

	if ( false !== $cached_data ) {
		wp_send_json_success( $cached_data );
	}

	// 發送遠端請求
	$api_url = 'https://api.abuseipdb.com/api/v2/check';
	$args    = [
		'method'      => 'GET',
		'timeout'     => 10, // 資安與效能：設定 Timeout 避免卡死
		'redirection' => 5,
		'httpversion' => '1.1',
		'headers'     => [
			'Key'    => $api_key,
			'Accept' => 'application/json',
		],
		'body'        => [
			'ipAddress'    => $ip,
			'maxAgeInDays' => 90,
		],
	];

	$response = wp_remote_get( $api_url, $args );

	// 錯誤處理
	if ( is_wp_error( $response ) ) {
		// Log 錯誤，但不直接顯示詳細技術細節給前端 (Privacy)
		error_log( 'AbuseIPDB Error: ' . $response->get_error_message() );
		wp_send_json_error( '連線遠端伺服器失敗' );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$data        = json_decode( $body, true );

	if ( 200 !== $status_code ) {
		// 處理 401 (Key 錯誤) 或 429 (超額)
		$error_msg = isset( $data['errors'][0]['detail'] ) ? sanitize_text_field( $data['errors'][0]['detail'] ) : 'API 請求錯誤';
		wp_send_json_error( $error_msg );
	}

	// 寫入快取 (12 小時 = 12 * HOUR_IN_SECONDS)
	// 注意：AbuseIPDB 建議不要太頻繁查詢同一 IP
	set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_tw365_check_ip_score', 'tw365_ajax_check_ip_score' );