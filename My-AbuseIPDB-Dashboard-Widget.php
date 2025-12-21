/**
 * Plugin Name: My-AbuseIPDB-Dashboard-Widget
 * Description: 在 WordPress 控制台新增小工具，透過 AbuseIPDB API 查詢 IP 信譽分數。具備資安防護、快取機制與優化 UI。
 * Version: 1.0.2 (Final/Documentation Enhanced)
 * Date: 2023-12-21
 * Author: WP TW Architect
 * * 架構設計摘要：
 * 1. Security: 嚴格檢查 Nonce 與 User Capabilities (manage_options)。
 * 2. Performance: API Key 採用 autoload='no' 儲存；查詢結果使用 Transients API 快取 12 小時。
 * 3. Reliability: 外部請求設定 10 秒 Timeout 防止進程卡死。
 * 4. UX: 使用 AJAX 非同步操作，並客製化按鈕顏色 (綠色查詢/紅色重設) 提升辨識度。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接存取檔案
}

/**
 * --------------------------------------------------------------------------
 * 1. 註冊 Dashboard Widget
 * --------------------------------------------------------------------------
 */
function tw365_register_abuseipdb_widget() {
	// [Security] 權限控管：僅限管理員可以看到此 Widget
	// 避免低權限使用者 (如編輯、作者) 看到敏感的管理工具
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'tw365_abuseipdb_widget',           // Widget ID (HTML ID)
		'🛡️ My-AbuseIPDB-Dashboard-Widget 信譽查詢', // Widget Title (標題)
		'tw365_render_abuseipdb_widget'     // Callback Function (內容渲染函式)
	);
}
// 使用 wp_dashboard_setup Hook 在後台初始化時註冊
add_action( 'wp_dashboard_setup', 'tw365_register_abuseipdb_widget' );

/**
 * --------------------------------------------------------------------------
 * 2. 渲染 Widget 內容 (HTML + CSS + JS)
 * --------------------------------------------------------------------------
 */
function tw365_render_abuseipdb_widget() {
	// 取得 API Key
	// 注意：此 Key 在儲存時已設定 autoload='no'，因此使用 get_option 讀取時才會產生 SQL 查詢，不會拖累全站載入速度
	$api_key = get_option( 'tw365_abuseipdb_api_key' );

	?>
	<style>
		/* 綠色按鈕 (查詢)：代表執行、通過 */
		.tw365-btn-green {
			background-color: #00a32a !important; /* WP Core Success Green */
			border-color: #008a20 !important;
			color: #fff !important;
		}
		.tw365-btn-green:hover, .tw365-btn-green:focus {
			background-color: #008a20 !important;
			border-color: #007c1e !important;
			color: #fff !important;
		}
		
		/* 紅色按鈕 (重設)：代表警告、刪除、危險操作 */
		.tw365-btn-red {
			background-color: #d63638 !important; /* WP Core Error Red */
			border-color: #cf2e31 !important;
			color: #fff !important;
		}
		.tw365-btn-red:hover, .tw365-btn-red:focus {
			background-color: #c92c2e !important;
			border-color: #b32d2e !important;
			color: #fff !important;
		}

		/* 佈局微調 */
		.tw365-abuseipdb-container { padding: 10px 0; }
		.tw365-abuseipdb-container .spinner { float: none; margin-left: 5px; }
	</style>

	<div class="tw365-abuseipdb-container">
		<?php if ( empty( $api_key ) ) : ?>
			<div id="tw365-apikey-form-wrapper">
				<p>請先輸入您的 AbuseIPDB API Key：</p>
				<form id="tw365-apikey-form">
					<p>
						<input type="password" id="tw365_api_key_input" class="widefat" placeholder="AbuseIPDB API Key" required>
					</p>
					<button type="submit" class="button button-primary">儲存設定</button>
					<span class="spinner"></span>
				</form>
			</div>
		<?php else : ?>
			<div id="tw365-ip-check-wrapper">
				<form id="tw365-ip-form" style="display: flex; gap: 5px; margin-bottom: 15px;">
					<input type="text" id="tw365_ip_input" class="widefat" placeholder="輸入 IP (例如: 8.8.8.8)" required>
					<button type="submit" class="button button-primary tw365-btn-green">查詢</button>
				</form>
				
				<div id="tw365-result-area" style="min-height: 50px; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
					<p class="description">請輸入 IP 進行查詢...</p>
				</div>
				
				<hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;">
				
				<button type="button" id="tw365-reset-key-btn" class="button button-primary tw365-btn-red">重設 API Key</button>
			</div>
		<?php endif; ?>

		<div id="tw365-status-msg" style="margin-top: 10px; color: #d63638; font-weight: bold;"></div>
	</div>

	<script type="text/javascript">
	jQuery(document).ready(function($) {
		
		// 輔助函式：顯示暫時性訊息
		function showStatus(msg, isError = false) {
			const color = isError ? '#d63638' : '#00a32a';
			$('#tw365-status-msg').css('color', color).text(msg).show().delay(3000).fadeOut();
		}

		// 事件 1: 儲存 API Key
		$('#tw365-apikey-form').on('submit', function(e) {
			e.preventDefault();
			const key = $('#tw365_api_key_input').val();
			const spinner = $(this).find('.spinner');
			
			spinner.addClass('is-active');

			// 發送 AJAX 請求
			$.post(ajaxurl, {
				action: 'tw365_save_abuseipdb_key',
				api_key: key,
				// [Security] 傳送 Nonce 進行 CSRF 防護
				nonce: '<?php echo esc_js( wp_create_nonce( 'tw365_abuseipdb_config' ) ); ?>'
			}).done(function(res) {
				spinner.removeClass('is-active');
				if (res.success) {
					showStatus('API Key 已儲存，請重新整理頁面。', false);
					// 成功後重新整理頁面以切換介面狀態
					setTimeout(function(){ location.reload(); }, 1000);
				} else {
					showStatus(res.data || '儲存失敗', true);
				}
			}).fail(function() {
				spinner.removeClass('is-active');
				showStatus('連線錯誤', true);
			});
		});

		// 事件 2: 查詢 IP
		$('#tw365-ip-form').on('submit', function(e) {
			e.preventDefault();
			const ip = $('#tw365_ip_input').val();
			const resultArea = $('#tw365-result-area');
			
			resultArea.html('<span class="spinner is-active" style="float:none;"></span> 查詢中...');

			$.post(ajaxurl, {
				action: 'tw365_check_ip_score',
				ip: ip,
				// [Security] 使用獨立的 Nonce 用於查詢動作
				nonce: '<?php echo esc_js( wp_create_nonce( 'tw365_abuseipdb_check' ) ); ?>'
			}).done(function(res) {
				if (res.success) {
					const data = res.data.data; // 解析 AbuseIPDB API 回傳結構
					// 根據分數動態調整顏色 (紅色危險，綠色安全)
					const scoreColor = data.abuseConfidenceScore > 50 ? '#d63638' : '#00a32a';
					
					let html = `
						<p><strong>IP:</strong> ${data.ipAddress}</p>
						<p><strong>所在地:</strong> ${data.countryCode} <img src="https://flagsapi.com/${data.countryCode}/flat/16.png" style="vertical-align:text-bottom;"></p>
						<p><strong>ISP:</strong> ${data.isp}</p>
						<p><strong>濫用評分:</strong> <span style="font-weight:bold; color: ${scoreColor};">${data.abuseConfidenceScore}%</span></p>
						<p><strong>最後回報:</strong> ${data.lastReportedAt || '無'}</p>
					`;
					resultArea.html(html);
				} else {
					resultArea.html('<span style="color:#d63638;">錯誤: ' + (res.data || '未知錯誤') + '</span>');
				}
			}).fail(function() {
				resultArea.html('<span style="color:#d63638;">連線失敗，請稍後再試。</span>');
			});
		});

		// 事件 3: 重設 API Key
		$('#tw365-reset-key-btn').on('click', function() {
			// [UX] 二次確認，防止誤觸
			if(!confirm('警告：這將會移除您的 API Key，確定要執行嗎？')) return;
			
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
 * --------------------------------------------------------------------------
 * 3. AJAX Handler: 儲存 API Key
 * --------------------------------------------------------------------------
 */
function tw365_ajax_save_abuseipdb_key() {
	// [Security] 驗證請求來源與時效性
	check_ajax_referer( 'tw365_abuseipdb_config', 'nonce' );

	// [Security] 雙重確認權限
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '權限不足' );
	}

	if ( empty( $_POST['api_key'] ) ) {
		wp_send_json_error( '請輸入 API Key' );
	}

	// [Security] 淨化輸入字串
	$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );

	// [Performance Optimization]
	// 這裡使用 add_option 並設定 autoload 為 'no'。
	// 因為 API Key 只有在管理員進入這個 Widget 時才需要，不需要在網站每一頁載入。
	// 若 Key 已存在，add_option 會失敗，則轉為使用 update_option。
	if ( false === get_option( 'tw365_abuseipdb_api_key' ) ) {
		add_option( 'tw365_abuseipdb_api_key', $api_key, '', 'no' );
	} else {
		update_option( 'tw365_abuseipdb_api_key', $api_key );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_tw365_save_abuseipdb_key', 'tw365_ajax_save_abuseipdb_key' );

/**
 * --------------------------------------------------------------------------
 * 4. AJAX Handler: 重設 API Key
 * --------------------------------------------------------------------------
 */
function tw365_ajax_reset_abuseipdb_key() {
	check_ajax_referer( 'tw365_abuseipdb_config', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '權限不足' );
	}
	
	// 刪除設定
	delete_option( 'tw365_abuseipdb_api_key' );
	wp_send_json_success();
}
add_action( 'wp_ajax_tw365_reset_abuseipdb_key', 'tw365_ajax_reset_abuseipdb_key' );

/**
 * --------------------------------------------------------------------------
 * 5. AJAX Handler: 查詢 IP (核心邏輯)
 * --------------------------------------------------------------------------
 */
function tw365_ajax_check_ip_score() {
	// [Security] Nonce 驗證
	check_ajax_referer( 'tw365_abuseipdb_check', 'nonce' );

	// [Security] 權限驗證
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '權限不足' );
	}

	// [Security] 輸入驗證：確保是合法的 IP 格式
	$raw_ip = isset( $_POST['ip'] ) ? wp_unslash( $_POST['ip'] ) : '';
	$ip     = filter_var( $raw_ip, FILTER_VALIDATE_IP );

	if ( ! $ip ) {
		wp_send_json_error( '無效的 IP 格式' );
	}

	$api_key = get_option( 'tw365_abuseipdb_api_key' );
	if ( ! $api_key ) {
		wp_send_json_error( 'API Key 尚未設定' );
	}

	// [Performance] 快取機制 (Transients API)
	// Key 格式: tw365_abuseipdb_{IP_MD5}
	// 將 IP 轉為 MD5 以確保 Cache Key 的字元安全性
	$cache_key = 'tw365_abuse_' . md5( $ip );
	$cached_data = get_transient( $cache_key );

	if ( false !== $cached_data ) {
		// 若有快取，直接回傳，不發送外部請求 (節省 API Quota 與時間)
		wp_send_json_success( $cached_data );
	}

	// 發送遠端請求設定
	$api_url = 'https://api.abuseipdb.com/api/v2/check';
	$args    = [
		'method'      => 'GET',
		'timeout'     => 10, // [Reliability] 設定 10 秒 Timeout，避免外部 API 回應過慢導致 Server 卡死
		'redirection' => 5,
		'httpversion' => '1.1',
		'headers'     => [
			'Key'    => $api_key,
			'Accept' => 'application/json',
		],
		'body'        => [
			'ipAddress'    => $ip,
			'maxAgeInDays' => 90, // 查詢過去 90 天的紀錄
		],
	];

	// 執行請求
	$response = wp_remote_get( $api_url, $args );

	// 錯誤處理
	if ( is_wp_error( $response ) ) {
		// [Privacy] 將詳細技術錯誤寫入 Error Log 供開發者除錯
		error_log( 'AbuseIPDB Error: ' . $response->get_error_message() );
		// 回傳給前端的訊息應保守，避免暴露系統細節
		wp_send_json_error( '連線遠端伺服器失敗' );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$data        = json_decode( $body, true );

	// 處理非 200 狀態 (如 401 Auth Failed, 429 Rate Limit)
	if ( 200 !== $status_code ) {
		$error_msg = isset( $data['errors'][0]['detail'] ) ? sanitize_text_field( $data['errors'][0]['detail'] ) : 'API 請求錯誤';
		wp_send_json_error( $error_msg );
	}

	// [Performance] 寫入快取
	// 設定有效期為 12 小時 (12 * HOUR_IN_SECONDS)
	set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_tw365_check_ip_score', 'tw365_ajax_check_ip_score' );