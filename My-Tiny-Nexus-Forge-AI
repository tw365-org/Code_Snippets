<?php
/*
Plugin Name: My-Tiny-Nexus-Forge-AI (Dashboard Widget)
Description: 懶人專用 - 直接在控制台首頁批量生成 AI 文章。
Version: 2.0.2 (Fix Title)
Author: WP TW Architect
*/

// 防止直接存取
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 檢查類別是否存在，避免重複貼上導致錯誤
if ( ! class_exists( 'Tw365_Dashboard_Forge' ) ) {

	class Tw365_Dashboard_Forge {

		const OPTION_KEY_API = 'tw365_nexus_openai_key';
		const NONCE_ACTION   = 'tw365_dashboard_forge_action';

		public function __construct() {
			// 1. 註冊 Dashboard Widget
			add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );
			
			// 2. 載入 JS
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			
			// 3. AJAX 處理 (儲存 Key + 生成文章)
			add_action( 'wp_ajax_tw365_save_key', [ $this, 'ajax_save_key' ] );
			add_action( 'wp_ajax_tw365_generate_post', [ $this, 'ajax_generate_single_post' ] );
		}

		/**
		 * 建立控制台小工具
		 */
		public function add_dashboard_widget() {
			wp_add_dashboard_widget(
				'tw365_nexus_forge_widget', // Widget ID
				'My-Tiny-Nexus-Forge-AI',   // <--- 這裡已經修正為你指定的名稱
				[ $this, 'render_widget_content' ] // 顯示內容的回呼函式
			);
		}

		/**
		 * 小工具的 HTML 內容
		 */
		public function render_widget_content() {
			$api_key = get_option( self::OPTION_KEY_API, '' );
			// 簡單遮罩顯示
			$display_key = $api_key ? substr( $api_key, 0, 3 ) . '***' . substr( $api_key, -3 ) : '';
			?>
			<div class="tw365-forge-wrap" style="padding: 5px;">
				<div style="margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
					<label><strong>OpenAI API Key:</strong></label>
					<div style="display: flex; gap: 5px; margin-top: 5px;">
						<input type="password" id="tw365-api-key" class="widefat" value="<?php echo esc_attr( $api_key ); ?>" placeholder="sk-..." autocomplete="new-password">
						<button type="button" id="tw365-save-key" class="button">儲存</button>
					</div>
					<small style="color: #666;">金鑰儲存在資料庫中，請安心使用。</small>
				</div>

				<div style="margin-bottom: 15px;">
					<label for="tw365-count"><strong>生成數量 (篇):</strong></label>
					<input type="number" id="tw365-count" value="1" min="1" max="10" class="small-text" style="margin-left: 5px;">
				</div>

				<button type="button" id="tw365-start" class="button button-primary button-hero" style="width: 100%; justify-content: center;">
					🚀 開始懶人生成
				</button>

				<div id="tw365-progress-area" style="margin-top: 15px; display: none;">
					<div style="background: #ddd; height: 10px; border-radius: 5px; overflow: hidden; margin-bottom: 5px;">
						<div id="tw365-bar" style="width: 0%; height: 100%; background: #2271b1; transition: width 0.3s;"></div>
					</div>
					<div id="tw365-status" style="font-weight: bold; font-size: 12px; margin-bottom: 5px;">準備中...</div>
					<div id="tw365-logs" style="height: 100px; overflow-y: auto; background: #f6f7f7; border: 1px solid #dcdcde; padding: 8px; font-size: 11px; line-height: 1.4;"></div>
				</div>
			</div>
			<?php
		}

		/**
		 * 載入 JS (僅在控制台首頁)
		 */
		public function enqueue_assets( $hook ) {
			if ( 'index.php' !== $hook ) {
				return;
			}

			// 這裡直接輸出 JS，避免額外檔案管理的麻煩
			$script_data = [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			];

			// 使用 Output Buffering 安全輸出 JS
			ob_start();
			?>
			jQuery(document).ready(function($) {
				const config = <?php echo json_encode( $script_data ); ?>;
				
				// Helper: 寫日誌
				function log(msg, color = '#333') {
					const time = new Date().toLocaleTimeString('zh-TW', {hour12:false});
					$('#tw365-logs').prepend(`<div style="color:${color}">[${time}] ${msg}</div>`);
				}

				// 1. 儲存 Key
				$('#tw365-save-key').click(function() {
					const key = $('#tw365-api-key').val().trim();
					if(!key) { alert('Key 不能為空'); return; }
					
					const $btn = $(this);
					$btn.prop('disabled', true).text('...');
					
					$.post(config.ajax_url, {
						action: 'tw365_save_key',
						_ajax_nonce: config.nonce,
						api_key: key
					}).done(function(res) {
						if(res.success) alert('✅ Key 已儲存！');
						else alert('❌ 失敗: ' + res.data);
					}).always(() => $btn.prop('disabled', false).text('儲存'));
				});

				// 2. 批量生成邏輯 (懶人佇列)
				$('#tw365-start').click(function() {
					const count = parseInt($('#tw365-count').val()) || 1;
					const key = $('#tw365-api-key').val().trim();

					if (!key) { alert('請先輸入並儲存 API Key'); return; }
					if (!confirm(`確定要呼叫 AI 幫你寫 ${count} 篇文章嗎？`)) return;

					// UI 重置
					const $btn = $(this);
					$btn.prop('disabled', true);
					$('#tw365-progress-area').slideDown();
					$('#tw365-logs').empty();
					$('#tw365-bar').css('width', '0%');
					
					let completed = 0;
					
					// 遞迴函式處理佇列 (避免 Timeout)
					function runQueue(index) {
						if (index >= count) {
							log('🎉 全部任務完成！', 'green');
							$btn.prop('disabled', false);
							return;
						}

						const currentNum = index + 1;
						log(`正在撰寫第 ${currentNum} / ${count} 篇...`, 'blue');
						$('#tw365-status').text(`處理中: ${currentNum} / ${count}`);

						$.post(config.ajax_url, {
							action: 'tw365_generate_post',
							_ajax_nonce: config.nonce,
							api_key: key
						}).done(function(res) {
							if (res.success) {
								log(`✅ 完成: ${res.data.title}`, 'green');
							} else {
								log(`❌ 錯誤: ${res.data}`, 'red');
							}
						}).fail(function() {
							log('❌ 嚴重錯誤: 連線中斷', 'red');
						}).always(function() {
							completed++;
							const percent = Math.round((completed / count) * 100);
							$('#tw365-bar').css('width', percent + '%');
							
							// 休息 1 秒再跑下一篇 (避免太快被 OpenAI 擋)
							setTimeout(() => runQueue(index + 1), 1000);
						});
					}

					runQueue(0);
				});
			});
			<?php
			$js_code = ob_get_clean();
			wp_add_inline_script( 'common', $js_code );
		}

		/**
		 * AJAX: 儲存 Key
		 */
		public function ajax_save_key() {
			check_ajax_referer( self::NONCE_ACTION );
			if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );
			
			$key = sanitize_text_field( $_POST['api_key'] );
			update_option( self::OPTION_KEY_API, $key ); // 這裡可以選擇不 autoload 以優化效能
			wp_send_json_success();
		}

		/**
		 * AJAX: 生成單篇文章 (核心)
		 */
		public function ajax_generate_single_post() {
			check_ajax_referer( self::NONCE_ACTION );
			if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

			$api_key = sanitize_text_field( $_POST['api_key'] );
			
			// --- 1. 定義 Prompt (你可以隨時修改這裡) ---
			$prompt = "你是一位繁體中文內容專家。請隨機發想一個關於「軟體開發」、「WordPress 架站」或「生產力工具」的主題。
			1. 標題：吸引人且清楚。
			2. 內容：約 500-800 字，使用 HTML 格式 (h2, p, ul)，語氣專業親切。
			3. 圖片提示：請提供一段英文 Prompt 用於 DALL-E 生成封面圖。
			4. 回傳格式：純 JSON，包含 keys: title, content, image_prompt。不要任何 Markdown 標記。";

			// --- 2. 呼叫 GPT ---
			$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
				'headers' => [ 
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json'
				],
				'body'    => json_encode([
					'model' => 'gpt-4o-mini', // 使用 4o-mini 性價比最高
					'messages' => [
						['role' => 'system', 'content' => 'You exist to output valid JSON only.'],
						['role' => 'user', 'content' => $prompt]
					],
					'temperature' => 0.8
				]),
				'timeout' => 30
			]);

			if ( is_wp_error( $response ) ) wp_send_json_error( 'GPT 連線失敗: ' . $response->get_error_message() );
			
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $body['error'] ) ) wp_send_json_error( 'OpenAI API 錯誤: ' . $body['error']['message'] );

			// 解析 JSON (防呆處理)
			$raw_content = $body['choices'][0]['message']['content'] ?? '';
			// 移除可能出現的 ```json ... ```
			$json_str = str_replace(['```json', '```'], '', $raw_content);
			$ai_data = json_decode( $json_str, true );

			if ( ! $ai_data || empty( $ai_data['title'] ) ) {
				// 萬一 JSON 解析失敗，還是把內容寫進去，方便除錯
				$ai_data = [
					'title' => 'AI 生成格式錯誤 (待修)',
					'content' => $raw_content,
					'image_prompt' => ''
				];
			}

			// --- 3. 呼叫 DALL-E (如果有 Prompt) ---
			$image_id = 0;
			if ( ! empty( $ai_data['image_prompt'] ) ) {
				$img_res = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
					'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
					'body' => json_encode([
						'model' => 'dall-e-3',
						'prompt' => $ai_data['image_prompt'],
						'n' => 1,
						'size' => '1024x1024'
					]),
					'timeout' => 45
				]);
				
				if ( ! is_wp_error( $img_res ) ) {
					$img_body = json_decode( wp_remote_retrieve_body( $img_res ), true );
					if ( ! empty( $img_body['data'][0]['url'] ) ) {
						$image_id = $this->sideload_image( $img_body['data'][0]['url'], $ai_data['title'] );
					}
				}
			}

			// --- 4. 寫入 WordPress ---
			$post_id = wp_insert_post([
				'post_title'   => $ai_data['title'],
				'post_content' => $ai_data['content'],
				'post_status'  => 'draft', // 先存草稿最安全
				'post_author'  => get_current_user_id(),
				'post_category' => [1] // 預設分類 ID 1
			]);

			if ( $image_id && ! is_wp_error( $image_id ) ) {
				set_post_thumbnail( $post_id, $image_id );
			}

			if ( is_wp_error( $post_id ) ) wp_send_json_error( '文章寫入失敗' );

			wp_send_json_success( [ 'title' => $ai_data['title'], 'post_id' => $post_id ] );
		}

		// 下載圖片並建立附件
		private function sideload_image( $url, $desc ) {
			require_once( ABSPATH . 'wp-admin/includes/media.php' );
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
			require_once( ABSPATH . 'wp-admin/includes/image.php' );

			$tmp = download_url( $url );
			if ( is_wp_error( $tmp ) ) return $tmp;

			$file_array = [
				'name'     => sanitize_title( $desc ) . '.png',
				'tmp_name' => $tmp,
			];

			$id = media_handle_sideload( $file_array, 0 );
			if ( is_wp_error( $id ) ) @unlink( $file_array['tmp_name'] );
			return $id;
		}
	}

	// 啟動它
	new Tw365_Dashboard_Forge();
}
