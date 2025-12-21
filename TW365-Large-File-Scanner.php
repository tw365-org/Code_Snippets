/**
 * Snippet Name: TW365-Large-File-Scanner
 * Description: 在控制台監控大型檔案，支援自訂掃描門檻 (MB/GB) 與全域設定記憶。
 * Version:     2.0.0 (Configurable Threshold)
 * Author:      WP TW Architect
 */

// -----------------------------------------------------------------------------
// 0. 環境防護 (Environment Guard)
// -----------------------------------------------------------------------------
if ( ! is_admin() ) {
    return;
}

// -----------------------------------------------------------------------------
// 1. 核心邏輯 (Core Logic)
// -----------------------------------------------------------------------------

if ( ! function_exists( 'tw365_get_scan_settings' ) ) {
    /**
     * 取得掃描設定 (含預設值)
     * @return array
     */
    function tw365_get_scan_settings() {
        return array(
            'val'  => (int) get_option( 'tw365_scan_limit_val', 10 ),      // 預設 10
            'unit' => get_option( 'tw365_scan_limit_unit', 'MB' ),         // 預設 MB
        );
    }
}

if ( ! function_exists( 'tw365_format_size' ) ) {
    function tw365_format_size( $bytes ) {
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $bytes = max( $bytes, 0 );
        $pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
        $pow   = min( $pow, count( $units ) - 1 );
        $bytes /= pow( 1024, $pow );
        return round( $bytes, 2 ) . ' ' . $units[ $pow ];
    }
}

if ( ! function_exists( 'tw365_scan_filesystem' ) ) {
    function tw365_scan_filesystem( $threshold_bytes ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }
        @ini_set( 'memory_limit', '512M' );

        $root_path = ABSPATH;
        $results   = array();

        try {
            $directory = new RecursiveDirectoryIterator( $root_path, RecursiveDirectoryIterator::SKIP_DOTS );
            $iterator  = new RecursiveIteratorIterator( $directory, RecursiveIteratorIterator::SELF_FIRST );

            foreach ( $iterator as $file ) {
                // 排除開發與快取目錄 (保留 backups 以偵測備份檔)
                if ( preg_match( '/(\.git|node_modules|wp-content\/cache)/', $file->getPathname() ) ) {
                    continue;
                }

                if ( $file->isFile() ) {
                    $size = $file->getSize();
                    if ( $size > $threshold_bytes ) {
                        $results[] = array(
                            'path' => $file->getPathname(),
                            'size' => $size,
                        );
                    }
                }
            }
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'TW365 Scan Error: ' . $e->getMessage() );
            }
        }

        usort( $results, function( $a, $b ) {
            return $b['size'] <=> $a['size'];
        } );

        return $results;
    }
}

// -----------------------------------------------------------------------------
// 2. 請求處理 (Request Handling - Save & Rescan)
// -----------------------------------------------------------------------------

if ( ! function_exists( 'tw365_handle_dashboard_actions' ) ) {
    /**
     * 處理設定儲存與重新掃描
     */
    function tw365_handle_dashboard_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // 監聽 Widget 的表單提交
        if ( isset( $_POST['tw365_action'] ) && 'save_config' === $_POST['tw365_action'] ) {
            
            // 1. 資安驗證
            check_admin_referer( 'tw365_config_nonce_action', 'tw365_config_nonce' );

            // 2. 資料清洗與驗證
            $new_val  = isset( $_POST['tw365_val'] ) ? absint( $_POST['tw365_val'] ) : 10;
            if ( $new_val < 1 ) { $new_val = 1; } // 最小限制

            $new_unit = isset( $_POST['tw365_unit'] ) ? sanitize_text_field( $_POST['tw365_unit'] ) : 'MB';
            if ( ! in_array( $new_unit, array( 'MB', 'GB' ), true ) ) {
                $new_unit = 'MB'; // 白名單防護
            }

            // 3. 儲存設定 (使用 add_option 確保 autoload=no，若已存在則 update)
            // 技巧：先判斷是否存在，不存在才 add 並設 autoload=no，存在則 update
            if ( false === get_option( 'tw365_scan_limit_val' ) ) {
                add_option( 'tw365_scan_limit_val', $new_val, '', 'no' );
                add_option( 'tw365_scan_limit_unit', $new_unit, '', 'no' );
            } else {
                update_option( 'tw365_scan_limit_val', $new_val );
                update_option( 'tw365_scan_limit_unit', $new_unit );
            }

            // 4. 清除舊快取 (強制下一次重新掃描)
            delete_transient( 'tw365_large_files_list' );

            // 5. 設定提示並重導向
            set_transient( 'tw365_scan_notice', 'updated', 45 );
            wp_safe_redirect( admin_url( 'index.php' ) );
            exit;
        }
    }
}
add_action( 'admin_init', 'tw365_handle_dashboard_actions' );

if ( ! function_exists( 'tw365_show_admin_notices' ) ) {
    function tw365_show_admin_notices() {
        if ( 'updated' === get_transient( 'tw365_scan_notice' ) ) {
            $settings = tw365_get_scan_settings();
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>TW365-Large-File-Scanner 監控：</strong> 設定已儲存。目前掃描門檻為 <strong><?php echo esc_html( $settings['val'] . $settings['unit'] ); ?></strong>，快取已更新。</p>
            </div>
            <?php
            delete_transient( 'tw365_scan_notice' );
        }
    }
}
add_action( 'admin_notices', 'tw365_show_admin_notices' );

// -----------------------------------------------------------------------------
// 3. 畫面渲染 (Widget Rendering)
// -----------------------------------------------------------------------------

if ( ! function_exists( 'tw365_render_dashboard_widget' ) ) {
    function tw365_render_dashboard_widget() {
        // --- A. 準備參數 ---
        $settings = tw365_get_scan_settings(); // 獲取 DB 設定
        $limit_val = $settings['val'];
        $limit_unit = $settings['unit'];

        // 計算 Bytes
        $base = ( 'GB' === $limit_unit ) ? 1073741824 : 1048576; // 1024^3 vs 1024^2
        $threshold_bytes = $limit_val * $base;

        $transient_key = 'tw365_large_files_list';
        $files         = get_transient( $transient_key );
        $from_cache    = true;

        // --- B. 執行掃描 (若無快取) ---
        if ( false === $files ) {
            $files      = tw365_scan_filesystem( $threshold_bytes );
            $from_cache = false;
            set_transient( $transient_key, $files, HOUR_IN_SECONDS );
        }

        // --- C. 分頁邏輯 ---
        $total_items  = count( $files );
        $per_page     = 5;
        $total_pages  = ceil( $total_items / $per_page );
        
        $current_page = isset( $_GET['tw365_page'] ) ? absint( $_GET['tw365_page'] ) : 1;
        if ( $current_page < 1 ) { $current_page = 1; }
        if ( $current_page > $total_pages && $total_pages > 0 ) { $current_page = $total_pages; }

        $offset      = ( $current_page - 1 ) * $per_page;
        $paged_files = array_slice( $files, $offset, $per_page );

        // --- D. 輸出 HTML ---
        ?>
        <div class="tw365-widget-container">
            
            <div style="background: #f6f7f7; padding: 10px; border: 1px solid #dcdcde; margin-bottom: 12px; border-radius: 4px;">
                <form method="post" action="" style="display: flex; align-items: center; gap: 5px; margin:0;">
                    <?php wp_nonce_field( 'tw365_config_nonce_action', 'tw365_config_nonce' ); ?>
                    <input type="hidden" name="tw365_action" value="save_config">
                    
                    <span style="font-size: 12px; font-weight: 600; color: #50575e;">篩選 ></span>
                    
                    <input type="number" name="tw365_val" value="<?php echo esc_attr( $limit_val ); ?>" min="1" max="9999" step="1" style="width: 60px; padding: 0 5px; height: 28px; font-size: 12px;">
                    
                    <select name="tw365_unit" style="height: 28px; line-height: 28px; padding: 0 20px 0 5px; font-size: 12px; min-height: 28px; vertical-align: middle;">
                        <option value="MB" <?php selected( $limit_unit, 'MB' ); ?>>MB</option>
                        <option value="GB" <?php selected( $limit_unit, 'GB' ); ?>>GB</option>
                    </select>
                    
                    <button type="submit" class="button button-primary button-small" style="margin-left: auto;">儲存並掃描</button>
                </form>
            </div>

            <div style="margin-bottom: 8px; font-size: 12px; color: #666; display: flex; justify-content: space-between;">
                <span><?php echo $from_cache ? '⚡ 快取資料 (1hr)' : '🔴 即時掃描完成'; ?></span>
                <span>共 <strong><?php echo esc_html( $total_items ); ?></strong> 個檔案</span>
            </div>

            <?php if ( empty( $files ) ) : ?>
                <div class="notice notice-info inline" style="margin: 0;">
                    <p>目前沒有超過 <strong><?php echo esc_html( $limit_val . $limit_unit ); ?></strong> 的檔案。</p>
                </div>
            <?php else : ?>
                <ul style="margin: 0; border-top: 1px solid #f0f0f0;">
                    <?php foreach ( $paged_files as $file ) : ?>
                        <li style="border-bottom: 1px solid #f0f0f0; padding: 8px 0; display: flex; justify-content: space-between; align-items: center;">
                            <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-right: 10px; width: 70%;" title="<?php echo esc_attr( $file['path'] ); ?>">
                                <code style="background: none; padding: 0; font-size: 11px; color: #2271b1;">
                                    <?php echo esc_html( str_replace( ABSPATH, '/', $file['path'] ) ); ?>
                                </code>
                            </div>
                            <strong style="font-size: 12px; color: #d63638; flex-shrink: 0;">
                                <?php echo esc_html( tw365_format_size( $file['size'] ) ); ?>
                            </strong>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ( $total_pages > 1 ) : ?>
                    <div style="margin-top: 12px; text-align: center; display: flex; justify-content: center; gap: 3px;">
                        <?php
                        $base_url = admin_url( 'index.php' );
                        $link     = function( $p ) use ( $base_url ) { 
                            return esc_url( add_query_arg( 'tw365_page', $p, $base_url ) ); 
                        };
                        
                        // Prev
                        if ( $current_page > 1 ) {
                            echo '<a href="' . $link(1) . '" class="button button-small">«</a>';
                            echo '<a href="' . $link($current_page - 1) . '" class="button button-small">‹</a>';
                        } else {
                            echo '<span class="button button-small disabled">«</span>';
                            echo '<span class="button button-small disabled">‹</span>';
                        }

                        // Status
                        echo '<span class="button button-small disabled" style="background:#fff; border-color:#dcdcde; color:#50575e; min-width: 60px;">' . 
                             esc_html( $current_page ) . ' / ' . esc_html( $total_pages ) . 
                             '</span>';

                        // Next
                        if ( $current_page < $total_pages ) {
                            echo '<a href="' . $link($current_page + 1) . '" class="button button-small">›</a>';
                            echo '<a href="' . $link($total_pages) . '" class="button button-small">»</a>';
                        } else {
                            echo '<span class="button button-small disabled">›</span>';
                            echo '<span class="button button-small disabled">»</span>';
                        }
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

if ( ! function_exists( 'tw365_register_dashboard_widget' ) ) {
    function tw365_register_dashboard_widget() {
        if ( current_user_can( 'manage_options' ) ) {
            $settings = tw365_get_scan_settings();
            $title = sprintf( '📂 TW365-Large-File-Scanner 大型檔案監控 (>%s%s)', $settings['val'], $settings['unit'] );
            
            wp_add_dashboard_widget(
                'tw365_large_file_widget',
                $title, // 標題會隨設定動態變化
                'tw365_render_dashboard_widget'
            );
        }
    }
}
add_action( 'wp_dashboard_setup', 'tw365_register_dashboard_widget' );