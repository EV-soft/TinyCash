; inc/env.tpl v:1.1.0 d:2026-07-02 i:evs
; Template for filling in connection data. Save it as env.ini
; For instruktions see file env.doc

; In this file you provide critical criteria for connecting to different systems. 
; Be careful. The file may only be found in the inc folder which is secured with .htaccess

;----DB-selector: -----------------------------

; Database select Type: "mysql" or "sqlite"
; Uncomment one of following two lines to Aktivate:
;ACTIVE_DB="mysql_config"
ACTIVE_DB="sqlite_config"

[mysql_config]
DB_TYPE="mysql"
DB_HOST="localhost"
DB_USER=""
DB_PASS=""
DB_NAME=""

[sqlite_config]
DB_TYPE="sqlite"
DB_PATH=""

;----Incomming mails: -------------------------

; 1. SALES: Copies of own issued customer invoices (Money IN)
IMAP_INVOICE_SERVER=""
IMAP_INVOICE_USER=""
IMAP_INVOICE_PASS=""

; 2. PURCHASE: Small expenses, receipts and vouchers (Money OUT)
IMAP_VOUCHER_SERVER=""
IMAP_VOUCHER_USER=""
IMAP_VOUCHER_PASS=""

; 3. PURCHASE: Real purchase/supplier invoices (Money OUT)
IMAP_VENDOR_SERVER=""
IMAP_VENDOR_USER=""
IMAP_VENDOR_PASS=""


;-----OpenAI-Document scanning and translate-----

; OpenAI Vision API Configuration (Receipt Scanning)
OPENAI_API_KEY=""

;----------------------------------------------
