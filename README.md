# WordPress 實用程式碼片段 (Code Snippets)

這裡收集了我在開發 WordPress 網站時所撰寫或整理的實用 PHP 程式碼片段 (Snippets)。主要聚焦於解決特定的網站管理或功能需求，方便開發者快速取用。

## 📂 內容列表

目前收錄以下功能的小工具 or 程式碼片段：

### 1. `My-AbuseIPDB-Dashboard-Widget.php`
* **功能**：AbuseIPDB 儀表板小工具。
* **用途**：在 WordPress 後台儀表板 (Dashboard) 新增一個 Widget，用於整合顯示 AbuseIPDB 的 IP 信譽資訊，協助管理者快速判讀潛在威脅。

### 2. `TW365-Large-File-Scanner.php`
* **功能**：網站大型檔案掃描器。
* **用途**：掃描 WordPress 目錄結構中的大型檔案，協助管理者清理磁碟空間或檢視異常佔用容量的檔案。

## 🚀 使用方式

您可以依據需求，選擇以下任一種方式來使用這些程式碼：

1.  **作為獨立外掛 (Plugin) 安裝**：
    * 下載特定的 `.php` 檔案。
    * 將檔案上傳至您的 WordPress `wp-content/plugins/` 資料夾中。
    * 登入 WordPress 後台，在「外掛」列表中啟用它。

2.  **作為必須使用外掛 (Must-Use Plugin) 安裝**：
    * 將檔案上傳至 `wp-content/mu-plugins/` 資料夾（若無此資料夾請自行在 `wp-content` 下建立）。
    * WordPress 會自動執行該目錄下的 PHP 檔案，無需手動啟用，且無法在後台停用。

3.  **整合至佈景主題 (Theme)**：
    * 複製程式碼內容 (不包含開頭的 `<?php`)。
    * 貼上至您子佈景主題 (Child Theme) 的 `functions.php` 檔案末端。
    * *注意：直接修改 `functions.php` 風險較高，操作前請務必備份。*

## 📝 授權 (License)

本專案採用 [GPL-3.0 授權](LICENSE)。歡迎自由使用、修改與分享。
