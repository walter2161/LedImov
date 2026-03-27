<?php
/**
 * Plugin Name: LedImov – Engine de Vendas para Incorporadoras
 * Description: Controle de estoque de unidades, portal do corretor, vitrine pública e proposta PDF automática. Versão sem leia.php.
 * Version:     2.1.0
 * Author:      LedMKT
 * Text Domain: ledimov
 */
if ( ! defined( 'ABSPATH' ) ) exit;

defined( 'LEDIMOV_VERSION' ) || define( 'LEDIMOV_VERSION', '2.1.0' );
defined( 'LEDIMOV_FILE' )    || define( 'LEDIMOV_FILE',    __FILE__ );
defined( 'LEDIMOV_DIR' )     || define( 'LEDIMOV_DIR',     plugin_dir_path( __FILE__ ) );
defined( 'LEDIMOV_URL' )     || define( 'LEDIMOV_URL',     plugin_dir_url( __FILE__ ) );

/* ============================================================
   1. ATIVAÇÃO / DESATIVAÇÃO
   ============================================================ */
register_activation_hook(   __FILE__, 'ledimov_activate' );
register_deactivation_hook( __FILE__, 'ledimov_deactivate' );

function ledimov_activate() {
    ledimov_create_tables();
    ledimov_migrate_tables();
    ledimov_schedule_cron();
    flush_rewrite_rules();
}
function ledimov_deactivate() {
    wp_clear_scheduled_hook( 'ledimov_check_reservations' );
}

/* ── Frontend Router ── */
add_action('template_redirect', 'ledimov_frontend_router');
function ledimov_frontend_router() {
    if ( empty($_GET['ledimov_prop']) ) return;
    $pid = intval($_GET['ledimov_prop']);
    if ( !$pid ) return;
    global $wpdb;
    $prop = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ledimov_properties WHERE id=%d AND status='active'", $pid
    ));
    if ( !$prop ) return;
    $slug = sanitize_title(isset($prop->title) ? $prop->title : '');
    $page = $slug ? get_page_by_path($slug) : null;
    if ( $page ) { wp_redirect(get_permalink($page->ID), 301); exit; }
    $co        = ledimov_get_company();
    $site_name = ($co['name'] ?: get_bloginfo('name'));
    status_header(200);
    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html(isset($prop->title)?$prop->title:''); ?> — <?php echo esc_html($site_name); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600;700;800;900&display=swap" rel="stylesheet">
<?php wp_head(); ?>
</head>
<body <?php body_class('ledimov-landing'); ?> style="margin:0;padding:0;background:#fff;">
<?php echo do_shortcode('[ledimov_card_imovel id="'.$pid.'"]'); ?>
<?php wp_footer(); ?>
</body></html><?php
    exit;
}

/* ── Seed ── */
add_action('init', 'ledimov_maybe_seed', 20);
function ledimov_maybe_seed() {
    if ( get_option('ledimov_seeded_v1') ) return;
    global $wpdb;
    $pt = $wpdb->prefix.'ledimov_properties';
    $ut = $wpdb->prefix.'ledimov_units';
    $gt = $wpdb->prefix.'ledimov_gallery';
    if ( intval($wpdb->get_var("SELECT COUNT(*) FROM {$pt}")) > 0 ) {
        update_option('ledimov_seeded_v1', 1); return;
    }
    $wpdb->insert($pt, array(
        'title'=>'Residencial Aurora','address'=>'Rua das Flores, 500','neighborhood'=>'Jardim Primavera',
        'city'=>'São Paulo','state'=>'SP','description'=>'Um lançamento moderno com infraestrutura completa de lazer, projeto arquitetônico premiado e localização privilegiada no coração do Jardim Primavera. Apartamentos de 2 e 3 dormitórios com sacada gourmet.',
        'amenities'=>'Piscina adulto e infantil,Salão de festas,Fitness completo,Playground,Quadra poliesportiva,Espaço gourmet,Bicicletário,Portaria 24h',
        'cover_url'=>'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&q=80',
        'whatsapp'=>'5511999990000','status'=>'active','featured'=>1,'featured_at'=>current_time('mysql'),
    ));
    $pid = $wpdb->insert_id;
    if ( !$pid ) { update_option('ledimov_seeded_v1', 1); return; }
    $torres = array('A','B');
    $tipos  = array(
        array('bedrooms'=>2,'area_util'=>52,'area_total'=>58,'price'=>320000,'entry_price'=>32000,'monthly_qty'=>240,'monthly_price'=>1890),
        array('bedrooms'=>3,'area_util'=>72,'area_total'=>80,'price'=>480000,'entry_price'=>48000,'monthly_qty'=>240,'monthly_price'=>2840),
    );
    $stats = array('available','available','available','available','available','reserved','available','sold');
    $si = 0;
    foreach($torres as $torre) {
        for($floor=1;$floor<=4;$floor++) {
            foreach($tipos as $ti=>$tipo) {
                $wpdb->insert($ut, array(
                    'property_id'=>$pid,'tower'=>$torre,'floor'=>$floor,'unit'=>$floor.'0'.($ti+1),
                    'bedrooms'=>$tipo['bedrooms'],'area_util'=>$tipo['area_util'],'area_total'=>$tipo['area_total'],
                    'price'=>$tipo['price'],'entry_price'=>$tipo['entry_price'],'monthly_qty'=>$tipo['monthly_qty'],
                    'monthly_price'=>$tipo['monthly_price'],'orientation'=>array('Norte','Sul','Leste','Oeste')[$si%4],
                    'status'=>$stats[$si%count($stats)],
                ));
                $si++;
            }
        }
    }
    $imgs = array(
        'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&q=80',
        'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=900&q=80',
        'https://images.unsplash.com/photo-1600047509782-20d39509f26d?w=900&q=80',
        'https://images.unsplash.com/photo-1592595896616-c37162298647?w=900&q=80',
        'https://images.unsplash.com/photo-1600566752227-8f6f3c42c7b0?w=900&q=80',
    );
    foreach($imgs as $idx=>$url) {
        $wpdb->insert($gt, array('ref_type'=>'property','ref_id'=>$pid,'attachment_id'=>0,'url'=>$url,'thumb_url'=>$url,'sort_order'=>$idx,'is_cover'=>($idx===0)?1:0,'gallery_type'=>'gallery'));
    }
    $slug = sanitize_title('Residencial Aurora');
    if ( !get_page_by_path($slug) ) {
        wp_insert_post(array('post_title'=>'Residencial Aurora','post_name'=>$slug,'post_content'=>'[ledimov_card_imovel id="'.$pid.'"]','post_status'=>'publish','post_type'=>'page'));
    }
    update_option('ledimov_seeded_v1', 1);
}

/* ============================================================
   2. BANCO DE DADOS
   ============================================================ */
function ledimov_create_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    $sql = array();
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_properties (
        id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(200) NOT NULL,address VARCHAR(300),neighborhood VARCHAR(150),
        city VARCHAR(100),state VARCHAR(50),description TEXT,amenities TEXT,legal_reg VARCHAR(100),
        logo_url VARCHAR(400),cover_url VARCHAR(400),whatsapp VARCHAR(30),
        status ENUM('active','inactive') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $c;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_units (
        id INT AUTO_INCREMENT PRIMARY KEY,property_id INT NOT NULL,tower VARCHAR(50) DEFAULT '',
        floor TINYINT UNSIGNED DEFAULT 0,unit VARCHAR(20) NOT NULL,final VARCHAR(10) DEFAULT '',
        bedrooms TINYINT UNSIGNED DEFAULT 0,bathrooms TINYINT UNSIGNED DEFAULT 0,
        area_util DECIMAL(8,2) DEFAULT 0,area_total DECIMAL(8,2) DEFAULT 0,price DECIMAL(14,2) DEFAULT 0,
        entry_price DECIMAL(14,2) DEFAULT 0,monthly_price DECIMAL(14,2) DEFAULT 0,monthly_qty SMALLINT UNSIGNED DEFAULT 0,
        correction VARCHAR(100) DEFAULT '',orientation VARCHAR(50) DEFAULT '',floor_plan_url VARCHAR(400) DEFAULT '',
        status ENUM('available','reserved','sold','blocked') DEFAULT 'available',
        reserved_until DATETIME NULL,reserved_by INT NULL,sold_by INT NULL,notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_property (property_id),INDEX idx_status (status)
    ) $c;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_brokers (
        id INT AUTO_INCREMENT PRIMARY KEY,wp_user_id INT NULL,name VARCHAR(150) NOT NULL,email VARCHAR(150) NOT NULL,
        phone VARCHAR(30),creci VARCHAR(30),agency VARCHAR(150),photo_url VARCHAR(400),
        password_hash VARCHAR(255),token VARCHAR(64),token_expires DATETIME NULL,
        status ENUM('active','inactive','pending') DEFAULT 'pending',created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $c;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,unit_id INT NOT NULL,broker_id INT NOT NULL,
        client_name VARCHAR(150),client_phone VARCHAR(30),client_email VARCHAR(150),expires_at DATETIME NOT NULL,
        status ENUM('active','expired','converted') DEFAULT 'active',notes TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $c;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,unit_id INT NOT NULL,broker_id INT NOT NULL,reservation_id INT NULL,
        client_name VARCHAR(150),client_phone VARCHAR(30),client_email VARCHAR(150),
        price_agreed DECIMAL(14,2),entry_agreed DECIMAL(14,2),notes TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $c;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,property_id INT NOT NULL,title VARCHAR(200),file_url VARCHAR(400),
        type ENUM('memorial','plant','folder','video','other') DEFAULT 'other',
        access ENUM('public','broker','admin') DEFAULT 'broker',created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $c;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_proposals (
        id INT AUTO_INCREMENT PRIMARY KEY,unit_id INT NOT NULL,broker_id INT NOT NULL,
        client_name VARCHAR(150),pdf_url VARCHAR(400),data_json LONGTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $c;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ledimov_gallery (
        id INT AUTO_INCREMENT PRIMARY KEY,ref_type ENUM('property','unit') DEFAULT 'property',ref_id INT NOT NULL,
        attachment_id INT NOT NULL,url VARCHAR(600) NOT NULL,thumb_url VARCHAR(600) DEFAULT '',
        caption VARCHAR(300) DEFAULT '',sort_order SMALLINT UNSIGNED DEFAULT 0,is_cover TINYINT(1) DEFAULT 0,
        gallery_type VARCHAR(20) DEFAULT 'gallery',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ref (ref_type, ref_id),INDEX idx_cover (ref_type, ref_id, is_cover)
    ) $c;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ( $sql as $q ) { dbDelta( $q ); }
}

function ledimov_migrate_tables() {
    global $wpdb;
    $pt = $wpdb->prefix . 'ledimov_properties';
    $ut = $wpdb->prefix . 'ledimov_units';
    $prop_cols = array(
        'title'=>"VARCHAR(200) NOT NULL DEFAULT ''",'address'=>"VARCHAR(300) DEFAULT ''",'neighborhood'=>"VARCHAR(150) DEFAULT ''",
        'city'=>"VARCHAR(100) DEFAULT ''",'state'=>"VARCHAR(50) DEFAULT ''",'description'=>"TEXT",'amenities'=>"TEXT",
        'legal_reg'=>"VARCHAR(100) DEFAULT ''",'logo_url'=>"VARCHAR(400) DEFAULT ''",'cover_url'=>"VARCHAR(400) DEFAULT ''",
        'whatsapp'=>"VARCHAR(30) DEFAULT ''",'status'=>"VARCHAR(20) DEFAULT 'active'",
        'created_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP",
        'featured'=>"TINYINT(1) DEFAULT 0",'featured_at'=>"DATETIME NULL",'video_url'=>"VARCHAR(600) DEFAULT ''",
        'location_text'=>"TEXT",'badge_label'=>"VARCHAR(80) DEFAULT ''",'delivery_date'=>"VARCHAR(80) DEFAULT ''",
        'plant_text'=>"TEXT",'ficha_text'=>"TEXT",'intro_text'=>"TEXT",'tags'=>"VARCHAR(400) DEFAULT ''",
        'google_maps_url'=>"VARCHAR(600) DEFAULT ''",
    );
    $unit_cols = array(
        'property_id'=>"INT NOT NULL DEFAULT 0",'tower'=>"VARCHAR(50) DEFAULT ''",'floor'=>"TINYINT UNSIGNED DEFAULT 0",
        'unit'=>"VARCHAR(20) DEFAULT ''",'final'=>"VARCHAR(10) DEFAULT ''",'bedrooms'=>"TINYINT UNSIGNED DEFAULT 0",
        'bathrooms'=>"TINYINT UNSIGNED DEFAULT 0",'area_util'=>"DECIMAL(8,2) DEFAULT 0",'area_total'=>"DECIMAL(8,2) DEFAULT 0",
        'price'=>"DECIMAL(14,2) DEFAULT 0",'entry_price'=>"DECIMAL(14,2) DEFAULT 0",'monthly_price'=>"DECIMAL(14,2) DEFAULT 0",
        'monthly_qty'=>"SMALLINT UNSIGNED DEFAULT 0",'correction'=>"VARCHAR(100) DEFAULT ''",'orientation'=>"VARCHAR(50) DEFAULT ''",
        'floor_plan_url'=>"VARCHAR(400) DEFAULT ''",'status'=>"VARCHAR(20) DEFAULT 'available",'reserved_until'=>"DATETIME NULL",
        'reserved_by'=>"INT NULL",'sold_by'=>"INT NULL",'notes'=>"TEXT",
        'created_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP",
    );
    $add_missing = function( $table, $cols ) use ( $wpdb ) {
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) return;
        $existing = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
        foreach ( $cols as $col => $def ) {
            if ( ! in_array( $col, $existing ) ) $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" );
        }
    };
    $add_missing( $pt, $prop_cols );
    $add_missing( $ut, $unit_cols );
    $gt = $wpdb->prefix . 'ledimov_gallery';
    if ( $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $gt) ) === $gt ) {
        $existing_gt = $wpdb->get_col( "SHOW COLUMNS FROM `{$gt}`", 0 );
        if ( ! in_array('gallery_type', $existing_gt) ) $wpdb->query("ALTER TABLE `{$gt}` ADD COLUMN `gallery_type` VARCHAR(20) DEFAULT 'gallery'");
    }
    $bt = $wpdb->prefix . 'ledimov_brokers';
    $broker_cols = array(
        'wp_user_id'=>"INT NULL",'name'=>"VARCHAR(150) NOT NULL DEFAULT ''",'email'=>"VARCHAR(150) NOT NULL DEFAULT ''",
        'phone'=>"VARCHAR(30) DEFAULT ''",'creci'=>"VARCHAR(30) DEFAULT ''",'agency'=>"VARCHAR(150) DEFAULT ''",
        'photo_url'=>"VARCHAR(400) DEFAULT ''",'password_hash'=>"VARCHAR(255) DEFAULT ''",'token'=>"VARCHAR(64) DEFAULT ''",
        'token_expires'=>"DATETIME NULL",'status'=>"VARCHAR(20) DEFAULT 'pending'",'created_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP",
    );
    $add_missing( $bt, $broker_cols );
    if ( $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $gt) ) !== $gt ) {
        $c = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$gt}` (id INT AUTO_INCREMENT PRIMARY KEY,ref_type VARCHAR(20) DEFAULT 'property',ref_id INT NOT NULL,attachment_id INT NOT NULL DEFAULT 0,url VARCHAR(600) NOT NULL DEFAULT '',thumb_url VARCHAR(600) DEFAULT '',caption VARCHAR(300) DEFAULT '',sort_order SMALLINT UNSIGNED DEFAULT 0,is_cover TINYINT(1) DEFAULT 0,gallery_type VARCHAR(20) DEFAULT 'gallery',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,INDEX idx_ref (ref_type, ref_id),INDEX idx_cover (ref_type, ref_id, is_cover)) {$c}");
    }
}

add_action( 'admin_init', function() {
    static $ran = false; if ( $ran ) return; $ran = true;
    ledimov_migrate_tables(); ledimov_create_tables();
});

function ledimov_schedule_cron() {
    if ( ! wp_next_scheduled( 'ledimov_check_reservations' ) )
        wp_schedule_event( time(), 'every_five_minutes', 'ledimov_check_reservations' );
}
add_filter( 'cron_schedules', function( $s ) {
    $s['every_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 Minutes' ); return $s;
});
add_action( 'ledimov_check_reservations', 'ledimov_expire_reservations' );
function ledimov_expire_reservations() {
    global $wpdb;
    $ut = $wpdb->prefix.'ledimov_units'; $rt = $wpdb->prefix.'ledimov_reservations'; $now = current_time('mysql');
    $expired = $wpdb->get_col( $wpdb->prepare("SELECT id FROM {$rt} WHERE status='active' AND expires_at < %s", $now));
    if ( $expired ) {
        $ids = implode(',', array_map('intval', $expired));
        $wpdb->query("UPDATE {$rt} SET status='expired' WHERE id IN ({$ids})");
        $unit_ids = $wpdb->get_col("SELECT unit_id FROM {$rt} WHERE id IN ({$ids})");
        if ( $unit_ids ) { $uids = implode(',', array_map('intval', $unit_ids)); $wpdb->query("UPDATE {$ut} SET status='available', reserved_until=NULL, reserved_by=NULL WHERE id IN ({$uids}) AND status='reserved'"); }
    }
}

/* ============================================================
   3. HELPERS
   ============================================================ */
function ledimov_money( $v ) { return 'R$ ' . number_format( (float)$v, 2, ',', '.' ); }
function ledimov_current_broker() {
    if ( isset( $_COOKIE['ledimov_broker_id'], $_COOKIE['ledimov_broker_token'] ) ) {
        global $wpdb; $t = $wpdb->prefix.'ledimov_brokers';
        $id = intval( $_COOKIE['ledimov_broker_id'] ); $tk = sanitize_text_field( $_COOKIE['ledimov_broker_token'] );
        return $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t} WHERE id=%d AND token=%s AND status='active' AND (token_expires IS NULL OR token_expires > %s)", $id, $tk, current_time('mysql')));
    }
    return null;
}
function ledimov_is_admin_user() { return current_user_can('manage_options'); }
function ledimov_get_company() {
    return array(
        'name'=>get_option('ledimov_company_name',''),'type'=>get_option('ledimov_company_type','incorporadora'),
        'cnpj'=>get_option('ledimov_company_cnpj',''),'creci'=>get_option('ledimov_company_creci',''),
        'address'=>get_option('ledimov_company_address',''),'city'=>get_option('ledimov_company_city',''),
        'state'=>get_option('ledimov_company_state',''),'phone'=>get_option('ledimov_company_phone',''),
        'whatsapp'=>get_option('ledimov_company_whatsapp',''),'email'=>get_option('ledimov_company_email',''),
        'website'=>get_option('ledimov_company_website',''),'logo_url'=>get_option('ledimov_company_logo',''),
        'slogan'=>get_option('ledimov_company_slogan',''),'about'=>get_option('ledimov_company_about',''),
        'instagram'=>get_option('ledimov_company_instagram',''),'facebook'=>get_option('ledimov_company_facebook',''),
        'color_primary'=>get_option('ledimov_company_color_primary','#c0392b'),
        'color_secondary'=>get_option('ledimov_company_color_secondary','#0e9f6e'),
    );
}
/* ── Template Engine ── */
function ledimov_has_tpl($key){return(bool)get_option('ledimov_tpl_'.$key,'');}
function ledimov_get_tpl($key){return get_option('ledimov_tpl_'.$key,'');}
function ledimov_eval_tpl($code,$vars=[]){
    extract($vars,EXTR_SKIP);
    ob_start();
    try{eval('?>'.$code);}
    catch(Throwable $e){ob_end_clean();return '<div style="background:#fdecea;color:#7b241c;padding:14px 18px;border-radius:8px;font-size:13px;margin:12px 0;font-family:monospace;"><strong>Erro no template:</strong> '.esc_html($e->getMessage()).'</div>';}
    return ob_get_clean();
}

function ledimov_get_gallery( $ref_type, $ref_id ) {
    global $wpdb; $gt = $wpdb->prefix.'ledimov_gallery';
    return $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$gt} WHERE ref_type=%s AND ref_id=%d ORDER BY sort_order ASC", $ref_type, (int)$ref_id));
}
function ledimov_property_url( $prop ) {
    if ( !$prop ) return '';
    $title = isset($prop->title)?$prop->title:''; $pid = isset($prop->id)?intval($prop->id):0;
    if ( !$title || !$pid ) return '';
    $slug = sanitize_title($title); $page = get_page_by_path($slug);
    if ( $page ) return get_permalink($page->ID);
    $page_id = wp_insert_post(array('post_title'=>$title,'post_name'=>$slug,'post_content'=>'[ledimov_card_imovel id="'.$pid.'"]','post_status'=>'publish','post_type'=>'page'));
    if ( $page_id && !is_wp_error($page_id) ) return get_permalink($page_id);
    return add_query_arg('ledimov_prop',$pid,home_url('/'));
}

/* ============================================================
   4. ENQUEUE ASSETS
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'ledimov_frontend_assets' );
function ledimov_frontend_assets() {
    wp_enqueue_style( 'ledimov-style', false );
    add_action( 'wp_head', 'ledimov_inline_css' );
    add_action( 'wp_footer', 'ledimov_inline_js' );
}
add_action( 'admin_enqueue_scripts', 'ledimov_admin_assets' );
function ledimov_admin_assets( $hook ) {
    add_action( 'admin_head', 'ledimov_inline_css' );
    add_action( 'admin_footer', 'ledimov_inline_js' );
    wp_enqueue_media(); wp_enqueue_script( 'jquery' ); wp_enqueue_script( 'jquery-ui-sortable' );
    if(isset($_GET['page'])&&$_GET['page']==='ledimov-templates'){
        wp_enqueue_code_editor(array('type'=>'application/x-httpd-php'));
        wp_enqueue_script('wp-theme-plugin-editor');
        wp_enqueue_style('wp-codemirror');
    }
}

function ledimov_inject_globals() {
    $co = ledimov_get_company(); ?>
<script>
window.ajaxurl      = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
window.ledimovNonce = '<?php echo wp_create_nonce('ledimov_nonce'); ?>';
window.ledimovCompany = <?php echo json_encode($co, JSON_UNESCAPED_UNICODE); ?>;
</script>
<?php }
add_action( 'wp_head',    'ledimov_inject_globals', 1 );
add_action( 'admin_head', 'ledimov_inject_globals', 1 );

/* ============================================================
   CSS INLINE
   ============================================================ */
function ledimov_inline_css() { ?>
<style id="ledimov-css">
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap');
:root{
  --li-accent:#c0392b;--li-accent-d:#922b21;--li-accent-l:#fdecea;
  --li-green:#1a7a4a;--li-green-l:#d4efdf;--li-yellow:#b7770d;--li-yellow-l:#fef5e4;
  --li-red:#c0392b;--li-red-l:#fdecea;--li-blue:#c0392b;--li-gray:#7f8c8d;
  --li-dark:#f5f6fa;--li-card:#ffffff;--li-card-2:#f0f2f5;--li-border:#dde1e7;
  --li-text:#1a1a2e;--li-text-2:#2c3e50;--li-muted:#6c7a89;
  --li-shadow:0 2px 10px rgba(0,0,0,.07);--li-shadow-md:0 6px 24px rgba(0,0,0,.11);
  --li-r:10px;--li-r-lg:16px;--li-font:'Montserrat',sans-serif;
}
*{box-sizing:border-box;}
.ledimov-wrap{font-family:var(--li-font);color:var(--li-text);background:var(--li-dark);min-height:60vh;padding:28px;-webkit-font-smoothing:antialiased;}
.ledimov-wrap *{font-family:var(--li-font);}
.ledimov-wrap h1,.ledimov-wrap h2,.ledimov-wrap h3,.ledimov-wrap h4,.wrap.ledimov-wrap h1,.wrap.ledimov-wrap h2{font-family:var(--li-font);font-weight:800;color:var(--li-text);letter-spacing:-.02em;}
.li-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;font-family:var(--li-font);letter-spacing:.03em;text-transform:uppercase;}
.li-available{background:var(--li-green-l);color:var(--li-green);border:1px solid #a9dfbf;}
.li-reserved{background:var(--li-yellow-l);color:var(--li-yellow);border:1px solid #f9e79f;}
.li-sold{background:var(--li-red-l);color:var(--li-red);border:1px solid #f5b7b1;}
.li-blocked{background:var(--li-card-2);color:var(--li-muted);border:1px solid var(--li-border);}
.li-status-badge{font-family:var(--li-font);font-size:11px;font-weight:700;}
.li-building-map{display:flex;flex-direction:column;gap:6px;padding:20px;background:var(--li-card);border-radius:var(--li-r-lg);border:1px solid var(--li-border);box-shadow:var(--li-shadow);}
.li-floor-row{display:flex;align-items:center;gap:6px;}
.li-floor-label{width:44px;text-align:right;font-size:10px;font-weight:700;color:var(--li-muted);font-family:var(--li-font);letter-spacing:.05em;text-transform:uppercase;}
.li-unit-cell{width:50px;height:46px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;font-family:var(--li-font);cursor:pointer;transition:transform .15s,box-shadow .15s;border:2px solid transparent;}
.li-unit-cell:hover{transform:scale(1.1);box-shadow:0 4px 14px rgba(0,0,0,.18);}
.li-unit-cell.available{background:var(--li-green);color:#fff;}
.li-unit-cell.reserved{background:#e67e22;color:#fff;}
.li-unit-cell.sold{background:var(--li-red);color:#fff;}
.li-unit-cell.blocked{background:#dfe6e9;color:var(--li-muted);}
.li-excel-wrap{overflow-x:auto;border-radius:var(--li-r);border:1px solid var(--li-border);box-shadow:var(--li-shadow);}
.li-excel{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--li-font);}
.li-excel thead th{background:var(--li-text);padding:11px 14px;text-align:left;font-weight:700;font-size:10px;color:#fff;white-space:nowrap;position:sticky;top:0;z-index:2;letter-spacing:.05em;text-transform:uppercase;}
.li-excel tbody tr{border-bottom:1px solid var(--li-border);transition:background .1s;}
.li-excel tbody tr:nth-child(even){background:#fafbfc;}
.li-excel tbody tr:hover{background:#fef5f4;}
.li-excel tbody td{padding:10px 14px;vertical-align:middle;color:var(--li-text-2);}
.li-cell-edit{background:transparent;border:none;color:var(--li-text);width:100%;font-size:13px;font-family:var(--li-font);}
.li-cell-edit:focus{outline:2px solid var(--li-accent);border-radius:4px;background:#fff5f4;padding:2px 4px;}
.li-select-status{background:#fff;color:var(--li-text);border:1px solid var(--li-border);border-radius:6px;padding:4px 8px;font-size:12px;cursor:pointer;font-family:var(--li-font);}
.li-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px;}
.li-card{background:var(--li-card);border-radius:var(--li-r-lg);overflow:hidden;border:1px solid var(--li-border);transition:transform .2s,box-shadow .2s;box-shadow:var(--li-shadow);}
.li-card:hover{transform:translateY(-5px);box-shadow:var(--li-shadow-md);}
.li-card-cover{height:190px;background:var(--li-card-2);display:flex;align-items:center;justify-content:center;overflow:hidden;}
.li-card-cover img{width:100%;height:100%;object-fit:cover;}
.li-card-body{padding:18px;}
.li-card-title{font-size:18px;font-weight:800;margin-bottom:4px;font-family:var(--li-font);color:var(--li-text);}
.li-card-addr{font-size:12px;color:var(--li-muted);margin-bottom:12px;font-weight:500;}
.li-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:var(--li-r);font-size:12px;font-weight:700;font-family:var(--li-font);cursor:pointer;border:none;transition:all .15s;letter-spacing:.04em;text-transform:uppercase;}
.li-btn:active{transform:scale(.97);}
.li-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.li-btn-primary{background:var(--li-accent);color:#fff;box-shadow:0 2px 8px rgba(192,57,43,.25);}
.li-btn-primary:hover{background:var(--li-accent-d);}
.li-btn-green{background:var(--li-green);color:#fff;}
.li-btn-outline{background:#fff;color:var(--li-text-2);border:1.5px solid var(--li-border);}
.li-btn-outline:hover{border-color:var(--li-accent);color:var(--li-accent);background:var(--li-accent-l);}
.li-overlay{position:fixed;inset:0;background:rgba(26,26,46,.45);backdrop-filter:blur(4px);z-index:9998;display:none;align-items:center;justify-content:center;}
.li-overlay.open{display:flex;}
.li-modal{background:var(--li-card);border-radius:var(--li-r-lg);padding:32px;width:min(640px,94vw);max-height:92vh;overflow-y:auto;position:relative;border:1px solid var(--li-border);box-shadow:0 20px 60px rgba(0,0,0,.14);}
.li-modal h3{margin:0 0 22px;font-size:20px;font-weight:800;color:var(--li-text);}
.li-modal-close{position:absolute;top:16px;right:16px;background:var(--li-card-2);border:1px solid var(--li-border);color:var(--li-muted);font-size:15px;cursor:pointer;border-radius:6px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;}
.li-portal{max-width:1000px;margin:0 auto;}
.li-portal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;padding-bottom:18px;border-bottom:2px solid var(--li-border);}
.li-stats-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px;}
.li-stat-box{background:var(--li-card);border-radius:var(--li-r);padding:18px 22px;flex:1;min-width:130px;border:1px solid var(--li-border);box-shadow:var(--li-shadow);}
.li-stat-box .val{font-size:30px;font-weight:900;font-family:var(--li-font);color:var(--li-text);}
.li-stat-box .lbl{font-size:10px;color:var(--li-muted);font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-top:4px;}
.li-form-group{margin-bottom:16px;}
.li-form-group label{display:block;font-size:10px;color:var(--li-muted);margin-bottom:5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-family:var(--li-font);}
.li-input{width:100%;background:#fff;border:1.5px solid var(--li-border);color:var(--li-text);border-radius:var(--li-r);padding:10px 13px;font-size:13px;font-family:var(--li-font);font-weight:500;transition:border-color .15s,box-shadow .15s;}
.li-input:focus{outline:none;border-color:var(--li-accent);box-shadow:0 0 0 3px rgba(192,57,43,.1);}
textarea.li-input{resize:vertical;line-height:1.6;}
select.li-input{cursor:pointer;}
.li-filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
.li-filter-select{background:#fff;color:var(--li-text);border:1.5px solid var(--li-border);border-radius:var(--li-r);padding:9px 13px;font-size:12px;font-family:var(--li-font);font-weight:500;}
.li-legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;font-size:11px;font-weight:700;font-family:var(--li-font);text-transform:uppercase;letter-spacing:.04em;}
.li-legend span{display:flex;align-items:center;gap:6px;}
.li-legend-dot{width:12px;height:12px;border-radius:3px;}
#li-toast{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.li-toast-item{background:#fff;border:1px solid var(--li-border);border-left:4px solid var(--li-accent);border-radius:var(--li-r);padding:12px 18px;font-size:12px;font-weight:600;font-family:var(--li-font);box-shadow:0 6px 20px rgba(0,0,0,.1);animation:li-slidein .3s ease;color:var(--li-text);}
@keyframes li-slidein{from{opacity:0;transform:translateX(40px);}to{opacity:1;transform:none;}}
.li-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;}
.li-gallery-item{position:relative;border-radius:var(--li-r);overflow:hidden;background:var(--li-card-2);border:2px solid var(--li-border);aspect-ratio:4/3;cursor:grab;transition:border-color .15s,transform .15s,box-shadow .15s;}
.li-gallery-item:hover{border-color:var(--li-accent);transform:scale(1.02);}
.li-gallery-item img{width:100%;height:100%;object-fit:cover;display:block;}
.li-gallery-badge{position:absolute;top:6px;left:6px;background:rgba(192,57,43,.9);color:#fff;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;font-family:var(--li-font);}
.li-gallery-actions{position:absolute;top:6px;right:6px;display:flex;gap:4px;opacity:0;transition:opacity .15s;}
.li-gallery-item:hover .li-gallery-actions{opacity:1;}
.li-gallery-actions button{background:rgba(192,57,43,.92);color:#fff;border:none;border-radius:4px;width:24px;height:24px;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.li-lightbox-overlay{position:fixed;inset:0;background:rgba(0,0,0,.93);z-index:99999;display:none;align-items:center;justify-content:center;flex-direction:column;}
.li-lightbox-overlay.open{display:flex;}
.li-lightbox-img{max-width:90vw;max-height:80vh;object-fit:contain;border-radius:10px;}
.li-lightbox-nav{display:flex;gap:16px;margin-top:18px;}
.li-lightbox-nav button{background:rgba(255,255,255,.12);border:none;color:#fff;padding:10px 22px;border-radius:8px;font-size:22px;cursor:pointer;}
.li-lightbox-close{position:absolute;top:20px;right:24px;background:none;border:none;color:#fff;font-size:30px;cursor:pointer;}
.li-lightbox-counter{color:rgba(255,255,255,.55);font-size:11px;margin-top:10px;font-family:var(--li-font);font-weight:700;letter-spacing:.1em;text-transform:uppercase;}
.li-fe-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-top:14px;}
.li-fe-gallery-item{aspect-ratio:4/3;overflow:hidden;border-radius:8px;cursor:pointer;border:2px solid transparent;transition:border-color .15s;}
.li-fe-gallery-item:hover{border-color:var(--li-accent);}
.li-fe-gallery-item img{width:100%;height:100%;object-fit:cover;}
.li-doc-list{display:flex;flex-direction:column;gap:6px;}
.li-doc-item{display:flex;align-items:center;gap:10px;background:var(--li-card-2);border:1px solid var(--li-border);border-radius:8px;padding:10px 12px;}
.li-proposal-preview{background:#fff;color:#111;padding:40px;border-radius:var(--li-r-lg);font-family:var(--li-font);max-width:700px;border:1px solid var(--li-border);}
.li-proposal-table{width:100%;border-collapse:collapse;margin-top:16px;}
.li-proposal-table td{padding:9px 12px;border:1px solid #e8ecef;font-size:13px;}
.li-proposal-table td:first-child{font-weight:700;background:#f8f9fa;width:40%;}
.wrap.ledimov-wrap{background:#f5f6fa;}

/* ============================================================
   CARDS DE DESTAQUE — CORRIGIDO: IMAGEM EM CIMA, CONTEÚDO EMBAIXO
   ============================================================ */
.li-destaque-grid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:24px;
  font-family:var(--li-font);
}
@media(max-width:900px){.li-destaque-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:580px){.li-destaque-grid{grid-template-columns:1fr;}}

/* Card: flex-column garante imagem no topo e body embaixo */
.li-destaque-card{
  background:var(--li-card);
  border-radius:var(--li-r-lg);
  overflow:hidden;
  border:1px solid var(--li-border);
  box-shadow:0 2px 14px rgba(0,0,0,.07);
  transition:transform .22s cubic-bezier(.34,1.36,.64,1), box-shadow .22s;
  display:flex;
  flex-direction:column;
  text-decoration:none;
  color:inherit;
}
.li-destaque-card:hover{transform:translateY(-6px);box-shadow:0 12px 36px rgba(0,0,0,.13);}

/* Foto: ocupa toda a largura, altura fixa */
.li-destaque-cover{
  position:relative;
  width:100%;
  height:200px;
  overflow:hidden;
  background:#e8edf2;
  flex-shrink:0;
}
.li-destaque-cover img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
  transition:transform .35s;
}
.li-destaque-card:hover .li-destaque-cover img{transform:scale(1.04);}
.li-destaque-cover-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:64px;color:#c5cdd6;}

/* Badge flutuante sobre a foto */
.li-destaque-badge{
  position:absolute;top:14px;left:14px;
  background:var(--li-green);color:#fff;
  font-size:11px;font-weight:700;font-family:var(--li-font);
  padding:5px 12px;border-radius:999px;letter-spacing:.02em;
  box-shadow:0 2px 8px rgba(0,0,0,.18);
}

/* Corpo: fica abaixo da foto */
.li-destaque-body{
  padding:20px 20px 16px;
  flex:1;
  display:flex;
  flex-direction:column;
}
.li-destaque-title{font-size:17px;font-weight:800;color:var(--li-text);margin:0 0 4px;font-family:var(--li-font);line-height:1.25;}
.li-destaque-subtitle{font-size:12px;font-weight:500;color:var(--li-muted);margin:0 0 12px;line-height:1.5;}
.li-destaque-infos{display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap;}
.li-destaque-info-item{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--li-text-2);}
.li-destaque-info-item svg{flex-shrink:0;opacity:.5;}
.li-destaque-footer{display:flex;justify-content:space-between;align-items:center;margin-top:auto;padding-top:12px;border-top:1px solid var(--li-border);}
.li-destaque-loc{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:500;color:var(--li-muted);}
.li-destaque-cta{font-size:13px;font-weight:800;color:var(--li-accent);text-decoration:none;font-family:var(--li-font);display:inline-flex;align-items:center;gap:4px;transition:gap .15s;}
.li-destaque-cta:hover{gap:8px;color:var(--li-accent-d);}

@media(max-width:640px){
  .ledimov-wrap{padding:16px;}
  .li-excel thead{display:none;}
  .li-excel tbody tr{display:block;margin-bottom:12px;border:1px solid var(--li-border);border-radius:var(--li-r);padding:10px;background:var(--li-card);}
  .li-excel tbody td{display:flex;justify-content:space-between;border:none;padding:5px 8px;font-size:12px;}
  .li-excel tbody td::before{content:attr(data-label);color:var(--li-muted);font-weight:700;font-size:10px;text-transform:uppercase;}
  .li-unit-cell{width:40px;height:38px;font-size:10px;}
  .li-stats-row{flex-direction:column;}
  .li-btn{font-size:11px;padding:9px 14px;}
}
</style>
<?php }

/* ============================================================
   JS INLINE
   ============================================================ */
function ledimov_inline_js() { ?>
<script id="ledimov-js">
(function($w){
window.liToast=function(msg,type){var el=document.getElementById('li-toast');if(!el){el=document.createElement('div');el.id='li-toast';document.body.appendChild(el);}var t=document.createElement('div');t.className='li-toast-item';var icons={success:'✅',error:'❌',info:'ℹ️',warning:'⚠️'};t.innerHTML=(icons[type]||'💬')+' '+msg;el.appendChild(t);setTimeout(function(){t.remove();},3500);};
window.liAjax=function(action,data,cb){var fd=new FormData();fd.append('action',action);fd.append('nonce',(window.ledimovNonce||''));for(var k in data)fd.append(k,data[k]);fetch(window.ajaxurl||'/wp-admin/admin-ajax.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){cb(null,r);}).catch(function(e){cb(e);});};
window.liModal={open:function(content){var o=document.getElementById('li-modal-overlay');if(!o){o=document.createElement('div');o.id='li-modal-overlay';o.className='li-overlay';o.innerHTML='<div class="li-modal"><button class="li-modal-close" onclick="liModal.close()">✕</button><div id="li-modal-body"></div></div>';document.body.appendChild(o);o.addEventListener('click',function(e){if(e.target===o)liModal.close();});}document.getElementById('li-modal-body').innerHTML=content;o.classList.add('open');},close:function(){var o=document.getElementById('li-modal-overlay');if(o)o.classList.remove('open');}};
document.addEventListener('change',function(e){var el=e.target;if(el.classList.contains('li-cell-edit')||el.classList.contains('li-select-status')){var row=el.closest('tr');if(!row)return;var uid=row.dataset.unitId,field=el.dataset.field,val=el.value;if(!uid||!field)return;liAjax('ledimov_update_unit',{unit_id:uid,field:field,value:val},function(err,res){if(err||!res.success)return liToast('Erro ao salvar','error');liToast('Salvo!','success');if(field==='status'){var badge=row.querySelector('.li-status-badge');var map={available:'🟢 Disponível',reserved:'🟡 Reservado',sold:'🔴 Vendido',blocked:'⚪ Bloqueado'};if(badge)badge.textContent=map[val]||val;}});}});
document.addEventListener('input',function(e){if(e.target.classList.contains('li-filter-select')||e.target.classList.contains('li-filter-input'))liApplyFilters();});
document.addEventListener('change',function(e){if(e.target.classList.contains('li-filter-select'))liApplyFilters();});
function liApplyFilters(){var rows=document.querySelectorAll('[data-unit-row]');var fFloor=document.getElementById('li-flt-floor'),fBeds=document.getElementById('li-flt-beds'),fArea=document.getElementById('li-flt-area'),fPrice=document.getElementById('li-flt-price');rows.forEach(function(r){var show=true;if(fFloor&&fFloor.value&&r.dataset.floor!==fFloor.value)show=false;if(fBeds&&fBeds.value&&r.dataset.bedrooms!==fBeds.value)show=false;if(fArea&&fArea.value&&parseFloat(r.dataset.area)<parseFloat(fArea.value))show=false;if(fPrice&&fPrice.value&&parseFloat(r.dataset.price)>parseFloat(fPrice.value))show=false;r.style.display=show?'':'none';});}
function liPollStatus(){var wrap=document.querySelector('[data-poll-property]');if(!wrap)return;var pid=wrap.dataset.pollProperty;liAjax('ledimov_poll_status',{property_id:pid},function(err,res){if(err||!res.success)return;res.data.forEach(function(u){var cell=document.querySelector('[data-cell-unit="'+u.id+'"]');if(cell)cell.className='li-unit-cell '+u.status;var row=document.querySelector('tr[data-unit-id="'+u.id+'"]');if(row){var badge=row.querySelector('.li-status-badge');var map={available:'🟢 Disponível',reserved:'🟡 Reservado',sold:'🔴 Vendido',blocked:'⚪ Bloqueado'};if(badge)badge.textContent=map[u.status]||u.status;}});}); }
setInterval(liPollStatus,30000);
window.liReserve=function(unitId){var html='<h3>Reservar Unidade</h3><div class="li-form-group"><label>Nome do Cliente</label><input class="li-input" id="li-rsv-name" placeholder="Nome completo"></div><div class="li-form-group"><label>WhatsApp</label><input class="li-input" id="li-rsv-phone"></div><div class="li-form-group"><label>E-mail</label><input class="li-input" id="li-rsv-email" type="email"></div><div class="li-form-group"><label>Observações</label><textarea class="li-input" id="li-rsv-notes" rows="3"></textarea></div><button class="li-btn li-btn-primary" onclick="liConfirmReserve('+unitId+')">⏱ Reservar por 30 min</button>';liModal.open(html);};
window.liConfirmReserve=function(unitId){liAjax('ledimov_reserve_unit',{unit_id:unitId,client_name:document.getElementById('li-rsv-name').value,client_phone:document.getElementById('li-rsv-phone').value,client_email:document.getElementById('li-rsv-email').value,notes:document.getElementById('li-rsv-notes').value},function(err,res){if(err||!res.success)return liToast(res?res.data:'Erro','error');liToast('Unidade reservada por 30 minutos!','success');liModal.close();setTimeout(function(){location.reload();},800);});};
window.liGenerateProposal=function(unitId){liAjax('ledimov_get_unit_detail',{unit_id:unitId},function(err,res){if(err||!res.success)return liToast('Erro','error');var u=res.data;var html='<h3>📄 Gerar Proposta</h3><div class="li-form-group"><label>Nome do Cliente</label><input class="li-input" id="li-prop-name"></div><div class="li-form-group"><label>Observações</label><textarea class="li-input" id="li-prop-notes" rows="2"></textarea></div><div class="li-proposal-preview"><h2>Proposta Comercial</h2><p style="color:#666;margin:0 0 16px;">'+new Date().toLocaleDateString('pt-BR')+'</p><table class="li-proposal-table"><tr><td>Empreendimento</td><td>'+u.property_title+'</td></tr><tr><td>Apartamento</td><td>Apto '+u.unit+' – '+u.floor+'º andar</td></tr><tr><td>Área Útil</td><td>'+u.area_util+' m²</td></tr><tr><td>Dormitórios</td><td>'+u.bedrooms+'</td></tr><tr><td>Valor Total</td><td style="font-weight:800;color:#16a34a;">R$ '+parseFloat(u.price).toLocaleString("pt-BR",{minimumFractionDigits:2})+'</td></tr><tr><td>Entrada</td><td>R$ '+parseFloat(u.entry_price).toLocaleString("pt-BR",{minimumFractionDigits:2})+'</td></tr><tr><td>Parcelas</td><td>'+u.monthly_qty+'x de R$ '+parseFloat(u.monthly_price).toLocaleString("pt-BR",{minimumFractionDigits:2})+'</td></tr></table><p style="margin-top:20px;font-size:12px;color:#999;">Válida por 24 horas.</p></div><div style="margin-top:16px;display:flex;gap:10px;"><button class="li-btn li-btn-primary" onclick="liSaveProposal('+unitId+')">💾 Salvar</button><button class="li-btn li-btn-outline" onclick="window.print()">🖨 Imprimir</button></div>';liModal.open(html);});};
window.liSaveProposal=function(unitId){liAjax('ledimov_save_proposal',{unit_id:unitId,client_name:(document.getElementById('li-prop-name')||{value:''}).value,notes:(document.getElementById('li-prop-notes')||{value:''}).value},function(err,res){if(err||!res.success)return liToast('Erro','error');liToast('Proposta salva!','success');liModal.close();});};
window.liDownloadTablePDF=function(propId,propTitle){liToast('Preparando PDF...','info');liAjax('ledimov_get_pdf_data',{property_id:propId},function(err,res){if(err||!res.success)return liToast('Erro ao buscar dados','error');var d=res.data;var loadScript=function(src,cb){if(document.querySelector('script[src="'+src+'"]'))return cb();var s=document.createElement('script');s.src=src;s.onload=cb;document.head.appendChild(s);};loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',function(){loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js',function(){liGeneratePDF(d);});});});};
window.liGeneratePDF=function(d){var jsPDF=(window.jspdf&&window.jspdf.jsPDF)||window.jsPDF;if(!jsPDF)return liToast('Biblioteca PDF não carregou.','error');var co=d.company||{},prop=d.property||{},units=d.units||[];var hexToRgb=function(hex){hex=hex.replace('#','');return[parseInt(hex.substring(0,2),16),parseInt(hex.substring(2,4),16),parseInt(hex.substring(4,6),16)];};var cp=hexToRgb(co.color_primary||'#c0392b'),cs=hexToRgb(co.color_secondary||'#0e9f6e');var doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});var W=doc.internal.pageSize.getWidth(),H=doc.internal.pageSize.getHeight(),margin=14;doc.setFillColor(cp[0],cp[1],cp[2]);doc.rect(0,0,W,22,'F');doc.setTextColor(255,255,255);doc.setFontSize(14);doc.setFont('helvetica','bold');doc.text(co.name||'Tabela',margin,10);doc.setFontSize(7);doc.text('Gerado em: '+d.generated,W-margin,10,{align:'right'});var y=30;doc.setTextColor(cp[0],cp[1],cp[2]);doc.setFontSize(16);doc.setFont('helvetica','bold');doc.text(prop.title||'',margin,y);y+=4;doc.setDrawColor(cp[0],cp[1],cp[2]);doc.setLineWidth(0.5);doc.line(margin,y,W-margin,y);y+=6;var statusLabel={available:'Disponível',reserved:'Reservado',sold:'Vendido',blocked:'Bloqueado'};var statusColor={available:[cs[0],cs[1],cs[2]],reserved:[234,179,8],sold:[239,68,68],blocked:[148,163,184]};doc.autoTable({head:[['Apto','Andar','Torre','Dorm.','Área Útil','Valor Total','Entrada','Parcelas','Status']],body:units.map(function(u){return[u.unit,u.floor+'º',u.tower||'-',u.bedrooms||'-',u.area_util?u.area_util.toFixed(0)+' m²':'-',u.price?'R$ '+u.price.toLocaleString('pt-BR',{minimumFractionDigits:0}):'-',u.entry_price?'R$ '+u.entry_price.toLocaleString('pt-BR',{minimumFractionDigits:0}):'-',u.monthly_qty&&u.monthly_price?u.monthly_qty+'x R$ '+u.monthly_price.toLocaleString('pt-BR',{minimumFractionDigits:0}):'-',statusLabel[u.status]||u.status];}),startY:y,margin:{left:margin,right:margin},styles:{fontSize:8,cellPadding:2.5,valign:'middle'},headStyles:{fillColor:cp,textColor:[255,255,255],fontStyle:'bold'},alternateRowStyles:{fillColor:[248,250,252]},didParseCell:function(data){if(data.section==='body'&&data.column.index===8){var s=units[data.row.index]?units[data.row.index].status:'';var c=statusColor[s]||[100,100,100];data.cell.styles.textColor=c;}},didDrawPage:function(){doc.setFontSize(7);doc.setTextColor(150,150,150);doc.text(co.name||'',margin,H-6);doc.text('Página '+doc.internal.getCurrentPageInfo().pageNumber+' de '+doc.internal.getNumberOfPages(),W-margin,H-6,{align:'right'});}});var filename=(prop.title||'tabela').replace(/[^a-z0-9]/gi,'_').toLowerCase();doc.save('tabela_'+filename+'_'+new Date().toISOString().slice(0,10)+'.pdf');liToast('PDF gerado!','success');};
window.liBrokerLogin=function(){var emailEl=document.getElementById('li-broker-email'),passEl=document.getElementById('li-broker-pass'),errEl=document.getElementById('li-login-error'),btn=document.getElementById('li-login-btn');if(!emailEl||!passEl)return;var email=emailEl.value.trim(),pass=passEl.value;function showErr(msg){if(errEl){errEl.textContent=msg;errEl.style.display='block';}else liToast(msg,'error');}if(!email||!pass)return showErr('Preencha e-mail e senha');if(errEl)errEl.style.display='none';if(btn){btn.disabled=true;btn.textContent='⏳ Entrando...';}liAjax('ledimov_broker_login',{email:email,password:pass},function(err,res){if(btn){btn.disabled=false;btn.textContent='Entrar →';}if(err||!res.success)return showErr((res&&res.data)?res.data:'Erro de conexão.');liToast('Bem-vindo(a), '+res.data.name+'!','success');setTimeout(function(){if(res.data.redirect&&res.data.redirect!==window.location.href){location.href=res.data.redirect;}else{location.reload();}},800);});};
var _liMediaFrames={};
function _liOpenMedia(opts){var useFallback=(typeof window.wp==='undefined')||(typeof window.wp.media==='undefined');if(useFallback){var input=opts.multiple?prompt('Cole URLs separadas por vírgula:'):prompt('Cole a URL:');if(!input)return;var urls=opts.multiple?input.split(',').map(function(u){return u.trim();}).filter(Boolean):[input.trim()];urls.forEach(function(u){opts.onSelect({url:u,thumb_url:u,attachment_id:0,caption:''}); });return;}var cacheKey=opts.cacheKey||'default';if(!_liMediaFrames[cacheKey])_liMediaFrames[cacheKey]=window.wp.media({title:opts.title||'Selecionar Imagem',button:{text:opts.btnText||'Usar imagem'},multiple:opts.multiple||false});var frame=_liMediaFrames[cacheKey];frame.off('select');frame.on('select',function(){var sel=frame.state().get('selection');sel.each(function(att){var a=att.toJSON();var thumb=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url;opts.onSelect({url:a.url,thumb_url:thumb,attachment_id:a.id,caption:a.caption||a.title||''});});});frame.open();}
window.liPickImage=function(inputId,previewId,fit){fit=fit||'cover';_liOpenMedia({cacheKey:'pick_'+inputId,title:'Selecionar Imagem',btnText:'Usar esta imagem',multiple:false,onSelect:function(img){var inp=document.getElementById(inputId),prev=document.getElementById(previewId);if(inp)inp.value=img.url;if(prev)prev.innerHTML='<img src="'+img.url+'" style="width:100%;height:100%;object-fit:'+fit+';">';}});};
window.liClearImage=function(inputId,previewId,icon){var inp=document.getElementById(inputId),prev=document.getElementById(previewId);if(inp)inp.value='';if(prev)prev.innerHTML='<span style="color:#64748b;font-size:20px;">'+(icon||'🖼')+'</span>';};
window.liPasteUrl=function(inputId,previewId,fit){var inp=document.getElementById(inputId);var url=prompt('Cole a URL:',inp?inp.value:'');if(url===null)return;url=url.trim();var prev=document.getElementById(previewId);if(inp)inp.value=url;if(prev&&url)prev.innerHTML='<img src="'+url+'" style="width:100%;height:100%;object-fit:'+(fit||'cover')+';">';else if(prev)prev.innerHTML='<span style="color:#64748b;font-size:20px;">🖼</span>';};
window.liPreviewFromInput=function(inputId,previewId,fit){var inp=document.getElementById(inputId),prev=document.getElementById(previewId);if(!inp||!prev)return;var url=inp.value.trim();if(url)prev.innerHTML='<img src="'+url+'" style="width:100%;height:100%;object-fit:'+(fit||'cover')+';" onerror="this.style.display=\'none\'">';else prev.innerHTML='<span style="color:#64748b;font-size:20px;">🖼</span>';};
window.liAddGalleryImages=function(refType,refId,gallery_type){gallery_type=gallery_type||'gallery';_liOpenMedia({cacheKey:'gallery_'+refType+'_'+refId+'_'+gallery_type,title:'Selecionar Imagens',btnText:'Adicionar à Galeria',multiple:true,onSelect:function(img){liAppendGalleryItem(refType,refId,img,gallery_type);}});};
window.liAppendGalleryItem=function(refType,refId,img,gallery_type){gallery_type=gallery_type||'gallery';var gridId='li-gallery-'+refType+'-'+refId+'-'+gallery_type;var grid=document.getElementById(gridId)||document.getElementById('li-ugallery-grid');if(!grid)return;var empty=document.getElementById('li-gallery-empty-'+refType+'-'+refId+'-'+gallery_type)||document.getElementById('li-gallery-empty-'+refType+'-'+refId);if(empty)empty.remove();var isCover=grid.children.length===0;var div=document.createElement('div');div.className='li-gallery-item';div.draggable=true;div.dataset.url=img.url;div.dataset.thumbUrl=img.thumb_url||img.url;div.dataset.attachmentId=img.attachment_id||0;div.dataset.caption=img.caption||'';div.innerHTML='<img src="'+(img.thumb_url||img.url)+'" alt="">'+(isCover?'<span class="li-gallery-badge">⭐ Capa</span>':'')+'<div class="li-gallery-actions"><button onclick="this.closest(\'.li-gallery-item\').remove();liUpdateCoverBadge(\''+gridId+'\')" title="Remover">✕</button></div>';grid.appendChild(div);liInitDragDrop(grid);};
window.liUpdateCoverBadge=function(gridId){var grid=document.getElementById(gridId);if(!grid)return;grid.querySelectorAll('.li-gallery-item').forEach(function(item,i){var badge=item.querySelector('.li-gallery-badge');if(i===0){if(!badge){badge=document.createElement('span');badge.className='li-gallery-badge';item.appendChild(badge);}badge.textContent='⭐ Capa';}else{if(badge)badge.remove();}});};
window.liSaveGalleryOrder=function(refType,refId,gallery_type){gallery_type=gallery_type||'gallery';var gridId='li-gallery-'+refType+'-'+refId+'-'+gallery_type;var grid=document.getElementById(gridId)||document.getElementById('li-ugallery-grid');if(!grid)return liToast('Grid não encontrado','error');var items=[];grid.querySelectorAll('.li-gallery-item').forEach(function(item,i){items.push({attachment_id:item.dataset.attachmentId||0,url:item.dataset.url||(item.querySelector('img')?item.querySelector('img').src:''),thumb_url:item.dataset.thumbUrl||(item.querySelector('img')?item.querySelector('img').src:''),caption:item.dataset.caption||'',sort_order:i,gallery_type:gallery_type});});liAjax('ledimov_save_gallery',{ref_type:refType,ref_id:refId,images:JSON.stringify(items)},function(err,res){if(err||!res.success)return liToast('Erro ao salvar','error');liToast('Galeria salva! ('+res.data.saved+' imagens)','success');liUpdateCoverBadge(gridId);});};
window.liDeleteGalleryItem=function(gid,refType,refId){if(!confirm('Remover esta imagem?'))return;liAjax('ledimov_delete_gallery_item',{id:gid},function(err,res){if(err||!res.success)return liToast('Erro','error');var item=document.querySelector('[data-gid="'+gid+'"]');if(item)item.remove();liToast('Imagem removida','success');});};
window.liInitDragDrop=function(grid){var dragSrc=null;grid.querySelectorAll('.li-gallery-item').forEach(function(item){var newItem=item.cloneNode(true);item.parentNode.replaceChild(newItem,item);newItem.addEventListener('dragstart',function(){dragSrc=newItem;newItem.style.opacity='.4';});newItem.addEventListener('dragend',function(){newItem.style.opacity='';grid.querySelectorAll('.li-gallery-item').forEach(function(i){i.classList.remove('drag-over');});});newItem.addEventListener('dragover',function(e){e.preventDefault();newItem.classList.add('drag-over');});newItem.addEventListener('dragleave',function(){newItem.classList.remove('drag-over');});newItem.addEventListener('drop',function(e){e.preventDefault();newItem.classList.remove('drag-over');if(dragSrc&&dragSrc!==newItem){var items=Array.from(grid.querySelectorAll('.li-gallery-item'));var srcI=items.indexOf(dragSrc),tgtI=items.indexOf(newItem);if(srcI<tgtI)grid.insertBefore(dragSrc,newItem.nextSibling);else grid.insertBefore(dragSrc,newItem);}});});};
window.liLightbox={imgs:[],cur:0,open:function(imgs,idx){this.imgs=imgs;this.cur=idx||0;var o=document.getElementById('li-lb-overlay');if(!o){o=document.createElement('div');o.id='li-lb-overlay';o.className='li-lightbox-overlay';o.innerHTML='<button class="li-lightbox-close" onclick="liLightbox.close()">✕</button><img id="li-lb-img" class="li-lightbox-img" src="" alt=""><div id="li-lb-counter" class="li-lightbox-counter"></div><div class="li-lightbox-nav"><button onclick="liLightbox.prev()">‹</button><button onclick="liLightbox.next()">›</button></div>';document.body.appendChild(o);o.addEventListener('click',function(e){if(e.target===o)liLightbox.close();});document.addEventListener('keydown',function(e){if(e.key==='ArrowLeft')liLightbox.prev();if(e.key==='ArrowRight')liLightbox.next();if(e.key==='Escape')liLightbox.close();});}o.classList.add('open');this._show();},_show:function(){var img=document.getElementById('li-lb-img'),cnt=document.getElementById('li-lb-counter');if(img)img.src=this.imgs[this.cur];if(cnt)cnt.textContent=(this.cur+1)+' / '+this.imgs.length;},prev:function(){this.cur=(this.cur-1+this.imgs.length)%this.imgs.length;this._show();},next:function(){this.cur=(this.cur+1)%this.imgs.length;this._show();},close:function(){var o=document.getElementById('li-lb-overlay');if(o)o.classList.remove('open');}};
})(window);
</script>
<?php }

/* ============================================================
   5. AJAX HANDLERS
   ============================================================ */
add_action('wp_ajax_ledimov_update_unit','ledimov_ajax_update_unit');
add_action('wp_ajax_nopriv_ledimov_update_unit','ledimov_ajax_update_unit');
function ledimov_ajax_update_unit(){check_ajax_referer('ledimov_nonce','nonce');if(!ledimov_is_admin_user())wp_send_json_error('Acesso negado');global $wpdb;$t=$wpdb->prefix.'ledimov_units';$uid=intval($_POST['unit_id']);$field=sanitize_key($_POST['field']);$value=sanitize_text_field($_POST['value']);$allowed=array('unit','tower','floor','final','bedrooms','bathrooms','area_util','area_total','price','entry_price','monthly_price','monthly_qty','correction','orientation','status','notes');if(!in_array($field,$allowed))wp_send_json_error('Campo não permitido');$wpdb->update($t,array($field=>$value),array('id'=>$uid));wp_send_json_success();}

add_action('wp_ajax_ledimov_reserve_unit','ledimov_ajax_reserve_unit');
add_action('wp_ajax_nopriv_ledimov_reserve_unit','ledimov_ajax_reserve_unit');
function ledimov_ajax_reserve_unit(){check_ajax_referer('ledimov_nonce','nonce');$broker=ledimov_current_broker();if(!$broker&&!ledimov_is_admin_user())wp_send_json_error('Faça login como corretor');global $wpdb;$ut=$wpdb->prefix.'ledimov_units';$rt=$wpdb->prefix.'ledimov_reservations';$uid=intval($_POST['unit_id']);$unit=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ut} WHERE id=%d",$uid));if(!$unit)wp_send_json_error('Unidade não encontrada');if($unit->status!=='available')wp_send_json_error('Unidade não disponível');$expires=date('Y-m-d H:i:s',strtotime('+30 minutes'));$broker_id=$broker?$broker->id:0;$wpdb->insert($rt,array('unit_id'=>$uid,'broker_id'=>$broker_id,'client_name'=>sanitize_text_field($_POST['client_name']??''),'client_phone'=>sanitize_text_field($_POST['client_phone']??''),'client_email'=>sanitize_email($_POST['client_email']??''),'expires_at'=>$expires,'status'=>'active','notes'=>sanitize_textarea_field($_POST['notes']??'')));$wpdb->update($ut,array('status'=>'reserved','reserved_until'=>$expires,'reserved_by'=>$broker_id),array('id'=>$uid));wp_send_json_success(array('expires_at'=>$expires));}

add_action('wp_ajax_ledimov_poll_status','ledimov_ajax_poll_status');
add_action('wp_ajax_nopriv_ledimov_poll_status','ledimov_ajax_poll_status');
function ledimov_ajax_poll_status(){global $wpdb;$t=$wpdb->prefix.'ledimov_units';$pid=intval($_POST['property_id']??0);$rows=$wpdb->get_results($wpdb->prepare("SELECT id,status FROM {$t} WHERE property_id=%d",$pid));wp_send_json_success($rows);}

add_action('wp_ajax_ledimov_get_unit_detail','ledimov_ajax_get_unit_detail');
add_action('wp_ajax_nopriv_ledimov_get_unit_detail','ledimov_ajax_get_unit_detail');
function ledimov_ajax_get_unit_detail(){check_ajax_referer('ledimov_nonce','nonce');global $wpdb;$ut=$wpdb->prefix.'ledimov_units';$pt=$wpdb->prefix.'ledimov_properties';$uid=intval($_POST['unit_id']);$row=$wpdb->get_row($wpdb->prepare("SELECT u.*,p.title as property_title FROM {$ut} u LEFT JOIN {$pt} p ON p.id=u.property_id WHERE u.id=%d",$uid));if(!$row)wp_send_json_error('Não encontrada');wp_send_json_success($row);}

add_action('wp_ajax_ledimov_save_proposal','ledimov_ajax_save_proposal');
add_action('wp_ajax_nopriv_ledimov_save_proposal','ledimov_ajax_save_proposal');
function ledimov_ajax_save_proposal(){check_ajax_referer('ledimov_nonce','nonce');$broker=ledimov_current_broker();global $wpdb;$t=$wpdb->prefix.'ledimov_proposals';$wpdb->insert($t,array('unit_id'=>intval($_POST['unit_id']),'broker_id'=>$broker?$broker->id:0,'client_name'=>sanitize_text_field($_POST['client_name']??''),'data_json'=>wp_json_encode($_POST)));wp_send_json_success();}

add_action('wp_ajax_ledimov_broker_register','ledimov_ajax_broker_register');
add_action('wp_ajax_nopriv_ledimov_broker_register','ledimov_ajax_broker_register');
function ledimov_ajax_broker_register(){check_ajax_referer('ledimov_nonce','nonce');global $wpdb;$t=$wpdb->prefix.'ledimov_brokers';$name=sanitize_text_field($_POST['name']??'');$email=sanitize_email($_POST['email']??'');$phone=sanitize_text_field($_POST['phone']??'');$creci=sanitize_text_field($_POST['creci']??'');$agency=sanitize_text_field($_POST['agency']??'');$pass=$_POST['password']??'';if(empty($name))wp_send_json_error('Nome é obrigatório');if(empty($email))wp_send_json_error('E-mail é obrigatório');if(strlen($pass)<6)wp_send_json_error('Senha deve ter pelo menos 6 caracteres');$exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE email=%s",$email));if($exists)wp_send_json_error('E-mail já cadastrado.');ledimov_migrate_tables();ledimov_create_tables();$hash=password_hash($pass,PASSWORD_DEFAULT);$auto_approve=get_option('ledimov_auto_approve_brokers','1');$status=($auto_approve==='1')?'active':'pending';$result=$wpdb->insert($t,array('name'=>$name,'email'=>$email,'phone'=>$phone,'creci'=>$creci,'agency'=>$agency,'password_hash'=>$hash,'status'=>$status,'created_at'=>current_time('mysql')));if($result===false)wp_send_json_error('Erro ao salvar: '.$wpdb->last_error);$broker_id=$wpdb->insert_id;if($status==='active'){$token=bin2hex(random_bytes(32));$expires=date('Y-m-d H:i:s',strtotime('+8 hours'));$wpdb->update($t,array('token'=>$token,'token_expires'=>$expires),array('id'=>$broker_id));setcookie('ledimov_broker_id',$broker_id,strtotime('+8 hours'),COOKIEPATH,COOKIE_DOMAIN,is_ssl(),true);setcookie('ledimov_broker_token',$token,strtotime('+8 hours'),COOKIEPATH,COOKIE_DOMAIN,is_ssl(),true);$broker_area=get_page_by_path('area-corretor');$redirect_url=$broker_area?get_permalink($broker_area->ID):'';wp_send_json_success(array('name'=>$name,'auto_login'=>true,'redirect'=>$redirect_url,'msg'=>'Conta criada! Bem-vindo(a), '.$name.'!'));}else{wp_send_json_success(array('name'=>$name,'auto_login'=>false,'msg'=>'Cadastro enviado! Aguarde a aprovação.'));}}

add_action('wp_ajax_ledimov_broker_login','ledimov_ajax_broker_login');
add_action('wp_ajax_nopriv_ledimov_broker_login','ledimov_ajax_broker_login');
function ledimov_ajax_broker_login(){check_ajax_referer('ledimov_nonce','nonce');global $wpdb;$t=$wpdb->prefix.'ledimov_brokers';$email=sanitize_email($_POST['email']??'');$pass=$_POST['password']??'';if(empty($email)||empty($pass))wp_send_json_error('Preencha e-mail e senha');$broker=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE email=%s",$email));if(!$broker)wp_send_json_error('E-mail não cadastrado.');$hash=isset($broker->password_hash)?$broker->password_hash:'';$ok=false;if($hash){if(password_verify($pass,$hash))$ok=true;if(!$ok&&md5($pass)===$hash)$ok=true;if(!$ok&&$pass===$hash)$ok=true;}if(!$ok)wp_send_json_error('Senha incorreta.');$status=isset($broker->status)?$broker->status:'pending';if($status==='inactive')wp_send_json_error('Conta desativada.');if($status==='pending')wp_send_json_error('Aguardando aprovação.');$token=bin2hex(random_bytes(32));$expires=date('Y-m-d H:i:s',strtotime('+8 hours'));$wpdb->update($t,array('token'=>$token,'token_expires'=>$expires),array('id'=>$broker->id));setcookie('ledimov_broker_id',$broker->id,strtotime('+8 hours'),COOKIEPATH,COOKIE_DOMAIN,is_ssl(),true);setcookie('ledimov_broker_token',$token,strtotime('+8 hours'),COOKIEPATH,COOKIE_DOMAIN,is_ssl(),true);$broker_area=get_page_by_path('area-corretor');$redirect_url=$broker_area?get_permalink($broker_area->ID):'';wp_send_json_success(array('name'=>$broker->name,'redirect'=>$redirect_url));}

add_action('wp_ajax_ledimov_broker_logout','ledimov_ajax_broker_logout');
add_action('wp_ajax_nopriv_ledimov_broker_logout','ledimov_ajax_broker_logout');
function ledimov_ajax_broker_logout(){setcookie('ledimov_broker_id','',time()-3600,COOKIEPATH,COOKIE_DOMAIN);setcookie('ledimov_broker_token','',time()-3600,COOKIEPATH,COOKIE_DOMAIN);wp_send_json_success();}

add_action('wp_ajax_ledimov_toggle_featured','ledimov_ajax_toggle_featured');
function ledimov_ajax_toggle_featured(){if(!wp_verify_nonce($_POST['nonce']??'','ledimov_nonce'))wp_send_json_error('Nonce inválido');if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');global $wpdb;$t=$wpdb->prefix.'ledimov_properties';$id=intval($_POST['id']??0);if(!$id)wp_send_json_error('ID inválido');$current=intval($wpdb->get_var($wpdb->prepare("SELECT featured FROM {$t} WHERE id=%d",$id)));if($current){$wpdb->update($t,array('featured'=>0,'featured_at'=>null),array('id'=>$id));wp_send_json_success(array('featured'=>false,'msg'=>'Destaque removido'));}else{$wpdb->update($t,array('featured'=>1,'featured_at'=>current_time('mysql')),array('id'=>$id));$count=intval($wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE featured=1"));wp_send_json_success(array('featured'=>true,'total'=>$count,'msg'=>'Destaque ativado'));}}

add_action('wp_ajax_ledimov_save_property','ledimov_ajax_save_property');
add_action('wp_ajax_nopriv_ledimov_save_property','ledimov_ajax_deny');
function ledimov_ajax_save_property(){if(!wp_verify_nonce($_POST['nonce']??'','ledimov_nonce'))wp_send_json_error('Nonce inválido.');if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão.');global $wpdb;$t=$wpdb->prefix.'ledimov_properties';ledimov_migrate_tables();ledimov_create_tables();$title=sanitize_text_field($_POST['title']??'');if(empty($title))wp_send_json_error('Nome obrigatório.');$data=array('title'=>$title,'address'=>sanitize_text_field($_POST['address']??''),'neighborhood'=>sanitize_text_field($_POST['neighborhood']??''),'city'=>sanitize_text_field($_POST['city']??''),'state'=>sanitize_text_field($_POST['state']??''),'description'=>sanitize_textarea_field($_POST['description']??''),'amenities'=>sanitize_textarea_field($_POST['amenities']??''),'legal_reg'=>sanitize_text_field($_POST['legal_reg']??''),'whatsapp'=>sanitize_text_field($_POST['whatsapp']??''),'logo_url'=>esc_url_raw($_POST['logo_url']??''),'cover_url'=>esc_url_raw($_POST['cover_url']??''),'video_url'=>esc_url_raw($_POST['video_url']??''),'location_text'=>sanitize_textarea_field($_POST['location_text']??''),'badge_label'=>sanitize_text_field($_POST['badge_label']??''),'delivery_date'=>sanitize_text_field($_POST['delivery_date']??''),'plant_text'=>sanitize_textarea_field($_POST['plant_text']??''),'ficha_text'=>sanitize_textarea_field($_POST['ficha_text']??''),'intro_text'=>sanitize_textarea_field($_POST['intro_text']??''),'tags'=>sanitize_text_field($_POST['tags']??''),'google_maps_url'=>esc_url_raw($_POST['google_maps_url']??''),'status'=>sanitize_key($_POST['status']??'active'),'featured'=>intval($_POST['featured']??0));if(!empty($_POST['featured'])&&intval($_POST['featured']))$data['featured_at']=current_time('mysql');$id=intval($_POST['id']??0);if($id){$result=$wpdb->update($t,$data,array('id'=>$id));if($result===false)wp_send_json_error('Erro: '.$wpdb->last_error);wp_send_json_success(array('id'=>$id,'msg'=>'Empreendimento atualizado!'));}else{$result=$wpdb->insert($t,$data);if($result===false)wp_send_json_error('Erro: '.$wpdb->last_error);wp_send_json_success(array('id'=>$wpdb->insert_id,'msg'=>'Empreendimento criado!'));}}

add_action('wp_ajax_ledimov_save_unit','ledimov_ajax_save_unit');
add_action('wp_ajax_nopriv_ledimov_save_unit','ledimov_ajax_deny');
function ledimov_ajax_save_unit(){check_ajax_referer('ledimov_nonce','nonce');if(!ledimov_is_admin_user())wp_send_json_error('Acesso negado');global $wpdb;$t=$wpdb->prefix.'ledimov_units';$data=array('property_id'=>intval($_POST['property_id']??0),'tower'=>sanitize_text_field($_POST['tower']??''),'floor'=>intval($_POST['floor']??0),'unit'=>sanitize_text_field($_POST['unit']??''),'final'=>sanitize_text_field($_POST['final']??''),'bedrooms'=>intval($_POST['bedrooms']??0),'bathrooms'=>intval($_POST['bathrooms']??0),'area_util'=>floatval($_POST['area_util']??0),'area_total'=>floatval($_POST['area_total']??0),'price'=>floatval($_POST['price']??0),'entry_price'=>floatval($_POST['entry_price']??0),'monthly_price'=>floatval($_POST['monthly_price']??0),'monthly_qty'=>intval($_POST['monthly_qty']??0),'correction'=>sanitize_text_field($_POST['correction']??''),'orientation'=>sanitize_text_field($_POST['orientation']??''),'status'=>sanitize_key($_POST['status']??'available'),'notes'=>sanitize_textarea_field($_POST['notes']??''));$id=intval($_POST['id']??0);if($id){$wpdb->update($t,$data,array('id'=>$id));wp_send_json_success(array('id'=>$id));}else{$wpdb->insert($t,$data);wp_send_json_success(array('id'=>$wpdb->insert_id));}}

add_action('wp_ajax_ledimov_delete_unit','ledimov_ajax_delete_unit');
function ledimov_ajax_delete_unit(){check_ajax_referer('ledimov_nonce','nonce');if(!ledimov_is_admin_user())wp_send_json_error('Acesso negado');global $wpdb;$wpdb->delete($wpdb->prefix.'ledimov_units',array('id'=>intval($_POST['id'])));wp_send_json_success();}

add_action('wp_ajax_ledimov_save_broker','ledimov_ajax_save_broker');
function ledimov_ajax_save_broker(){check_ajax_referer('ledimov_nonce','nonce');if(!ledimov_is_admin_user())wp_send_json_error('Acesso negado');global $wpdb;$t=$wpdb->prefix.'ledimov_brokers';ledimov_migrate_tables();$id=intval($_POST['id']??0);$name=sanitize_text_field($_POST['name']??'');$email=sanitize_email($_POST['email']??'');$phone=sanitize_text_field($_POST['phone']??'');$creci=sanitize_text_field($_POST['creci']??'');$agency=sanitize_text_field($_POST['agency']??'');$status=sanitize_key($_POST['status']??'active');$pass=$_POST['password']??'';if(empty($name))wp_send_json_error('Nome é obrigatório');if(empty($email))wp_send_json_error('E-mail é obrigatório');if(!$id&&empty($pass))wp_send_json_error('Senha é obrigatória');$existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE email=%s AND id != %d",$email,$id));if($existing)wp_send_json_error('E-mail já cadastrado para outro corretor');$data=array('name'=>$name,'email'=>$email,'phone'=>$phone,'creci'=>$creci,'agency'=>$agency,'status'=>$status);if(!empty($pass))$data['password_hash']=password_hash($pass,PASSWORD_DEFAULT);if($id){$result=$wpdb->update($t,$data,array('id'=>$id));if($result===false)wp_send_json_error('Erro: '.$wpdb->last_error);wp_send_json_success(array('id'=>$id,'msg'=>'Corretor atualizado!'));}else{$result=$wpdb->insert($t,$data);if($result===false)wp_send_json_error('Erro: '.$wpdb->last_error);wp_send_json_success(array('id'=>$wpdb->insert_id,'msg'=>'Corretor criado!'));}}

function ledimov_ajax_deny(){wp_send_json_error('Acesso negado');}

/* Galeria */
add_action('wp_ajax_ledimov_save_gallery','ledimov_ajax_save_gallery');
function ledimov_ajax_save_gallery(){if(!wp_verify_nonce($_POST['nonce']??'','ledimov_nonce'))wp_send_json_error('Nonce inválido');if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');global $wpdb;$gt=$wpdb->prefix.'ledimov_gallery';$ref_type=sanitize_key($_POST['ref_type']??'property');$ref_id=intval($_POST['ref_id']??0);$images=json_decode(stripslashes($_POST['images']??'[]'),true);if(!$ref_id||!in_array($ref_type,['property','unit']))wp_send_json_error('Dados inválidos');$wpdb->delete($gt,array('ref_type'=>$ref_type,'ref_id'=>$ref_id));foreach((array)$images as $i=>$img){$wpdb->insert($gt,array('ref_type'=>$ref_type,'ref_id'=>$ref_id,'attachment_id'=>intval($img['attachment_id']??0),'url'=>esc_url_raw($img['url']??''),'thumb_url'=>esc_url_raw($img['thumb_url']??''),'caption'=>sanitize_text_field($img['caption']??''),'sort_order'=>$i,'is_cover'=>$i===0?1:0,'gallery_type'=>sanitize_key($img['gallery_type']??'gallery')));}if($ref_type==='property'&&!empty($images[0]['url'])){$pt=$wpdb->prefix.'ledimov_properties';$cover=$images[0]['url'];$current=$wpdb->get_var($wpdb->prepare("SELECT cover_url FROM {$pt} WHERE id=%d",$ref_id));if(empty($current))$wpdb->update($pt,array('cover_url'=>$cover),array('id'=>$ref_id));}wp_send_json_success(array('saved'=>count($images)));}

add_action('wp_ajax_ledimov_get_gallery','ledimov_ajax_get_gallery');
add_action('wp_ajax_nopriv_ledimov_get_gallery','ledimov_ajax_get_gallery');
function ledimov_ajax_get_gallery(){global $wpdb;$gt=$wpdb->prefix.'ledimov_gallery';$ref_type=sanitize_key($_POST['ref_type']??'property');$ref_id=intval($_POST['ref_id']??0);if(!$ref_id)wp_send_json_error('ref_id obrigatório');$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$gt} WHERE ref_type=%s AND ref_id=%d ORDER BY sort_order ASC",$ref_type,$ref_id));wp_send_json_success($rows);}

add_action('wp_ajax_ledimov_delete_gallery_item','ledimov_ajax_delete_gallery_item');
function ledimov_ajax_delete_gallery_item(){if(!wp_verify_nonce($_POST['nonce']??'','ledimov_nonce'))wp_send_json_error('Nonce inválido');if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');global $wpdb;$wpdb->delete($wpdb->prefix.'ledimov_gallery',array('id'=>intval($_POST['id'])));wp_send_json_success();}

add_action('wp_ajax_ledimov_force_migrate','ledimov_ajax_force_migrate');
function ledimov_ajax_force_migrate(){if(!wp_verify_nonce($_POST['nonce']??'','ledimov_nonce'))wp_send_json_error('Nonce inválido');if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');global $wpdb;$pt=$wpdb->prefix.'ledimov_properties';$ut=$wpdb->prefix.'ledimov_units';ledimov_create_tables();ledimov_migrate_tables();$prop_cols=$wpdb->get_col("SHOW COLUMNS FROM `{$pt}`",0);$unit_cols=[];if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$ut))===$ut)$unit_cols=$wpdb->get_col("SHOW COLUMNS FROM `{$ut}`",0);$required_prop=['id','title','address','neighborhood','city','state','description','amenities','legal_reg','logo_url','cover_url','whatsapp','status'];$missing_prop=array_diff($required_prop,$prop_cols);wp_send_json_success(array('properties_columns'=>$prop_cols,'units_columns'=>$unit_cols,'missing_prop'=>array_values($missing_prop),'ok'=>empty($missing_prop),'msg'=>empty($missing_prop)?'✅ Banco de dados OK!':'⚠️ Ainda faltam: '.implode(', ',$missing_prop)));}

add_action('wp_ajax_ledimov_run_seed','ledimov_ajax_run_seed');
function ledimov_ajax_run_seed(){if(!wp_verify_nonce($_POST['nonce']??'','ledimov_nonce'))wp_send_json_error('Nonce inválido');if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');delete_option('ledimov_seeded_v1');ledimov_maybe_seed();wp_send_json_success(array('msg'=>'✅ Empreendimento de exemplo criado!'));}

/* ── Template Editor AJAX ── */
add_action('wp_ajax_ledimov_save_tpl','ledimov_ajax_save_tpl');
function ledimov_ajax_save_tpl(){
    check_ajax_referer('ledimov_nonce','nonce');
    if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');
    $allowed=['card_imovel','area_corretor','vitrine','destaque','tabela','mapa'];
    $key=sanitize_key($_POST['key']??'');
    if(!in_array($key,$allowed))wp_send_json_error('Chave inválida');
    $code=wp_unslash($_POST['code']??'');
    update_option('ledimov_tpl_'.$key,$code,false);
    wp_send_json_success('Template salvo!');
}

add_action('wp_ajax_ledimov_reset_tpl','ledimov_ajax_reset_tpl');
function ledimov_ajax_reset_tpl(){
    check_ajax_referer('ledimov_nonce','nonce');
    if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');
    $key=sanitize_key($_POST['key']??'');
    delete_option('ledimov_tpl_'.$key);
    wp_send_json_success('Template restaurado ao padrão!');
}

add_action('wp_ajax_ledimov_get_default_tpl','ledimov_ajax_get_default_tpl');
function ledimov_ajax_get_default_tpl(){
    check_ajax_referer('ledimov_nonce','nonce');
    if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');
    $map=['card_imovel'=>'ledimov_sc_card_imovel','area_corretor'=>'ledimov_sc_area_corretor','vitrine'=>'ledimov_sc_vitrine','destaque'=>'ledimov_sc_destaque','tabela'=>'ledimov_sc_tabela','mapa'=>'ledimov_sc_mapa'];
    $key=sanitize_key($_POST['key']??'');
    if(!isset($map[$key]))wp_send_json_error('Chave inválida');
    $fn=$map[$key];
    if(!function_exists($fn))wp_send_json_error('Função não encontrada');
    $rf=new ReflectionFunction($fn);
    $file=$rf->getFileName();
    $start=$rf->getStartLine()-1;
    $end=$rf->getEndLine();
    $lines=file($file);
    $code=implode('',array_slice($lines,$start,$end-$start));
    wp_send_json_success(array('code'=>$code,'fn'=>$fn));
}

add_action('wp_ajax_ledimov_get_pdf_data','ledimov_ajax_get_pdf_data');
add_action('wp_ajax_nopriv_ledimov_get_pdf_data','ledimov_ajax_get_pdf_data');
function ledimov_ajax_get_pdf_data(){global $wpdb;$pid=intval($_POST['property_id']??0);if(!$pid)wp_send_json_error('property_id obrigatório');$ut=$wpdb->prefix.'ledimov_units';$pt=$wpdb->prefix.'ledimov_properties';$prop=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d",$pid));if(!$prop)wp_send_json_error('Não encontrado');$broker=ledimov_current_broker();$is_admin=ledimov_is_admin_user();if($broker||$is_admin)$units=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$ut} WHERE property_id=%d ORDER BY floor,unit",$pid));else $units=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$ut} WHERE property_id=%d AND status='available' ORDER BY floor,unit",$pid));$rows=array();foreach($units as $u){$rows[]=array('unit'=>isset($u->unit)?$u->unit:'','floor'=>isset($u->floor)?intval($u->floor):0,'tower'=>isset($u->tower)?$u->tower:'','final'=>isset($u->final)?$u->final:'','bedrooms'=>isset($u->bedrooms)?intval($u->bedrooms):0,'area_util'=>isset($u->area_util)?floatval($u->area_util):0,'area_total'=>isset($u->area_total)?floatval($u->area_total):0,'price'=>isset($u->price)?floatval($u->price):0,'entry_price'=>isset($u->entry_price)?floatval($u->entry_price):0,'monthly_qty'=>isset($u->monthly_qty)?intval($u->monthly_qty):0,'monthly_price'=>isset($u->monthly_price)?floatval($u->monthly_price):0,'orientation'=>isset($u->orientation)?$u->orientation:'','status'=>isset($u->status)?$u->status:'available');}$co=ledimov_get_company();wp_send_json_success(array('property'=>array('id'=>$pid,'title'=>isset($prop->title)?$prop->title:'','address'=>isset($prop->address)?$prop->address:'','neighborhood'=>isset($prop->neighborhood)?$prop->neighborhood:'','city'=>isset($prop->city)?$prop->city:'','state'=>isset($prop->state)?$prop->state:'','legal_reg'=>isset($prop->legal_reg)?$prop->legal_reg:'','whatsapp'=>isset($prop->whatsapp)?$prop->whatsapp:'','cover_url'=>isset($prop->cover_url)?$prop->cover_url:''),'units'=>$rows,'company'=>$co,'is_broker'=>(bool)($broker||$is_admin),'generated'=>date_i18n('d/m/Y H:i')));}

add_action('wp_ajax_ledimov_save_settings','ledimov_ajax_save_settings');
function ledimov_ajax_save_settings(){if(!wp_verify_nonce($_POST['nonce']??'','ledimov_nonce'))wp_send_json_error('Nonce inválido');if(!current_user_can('manage_options'))wp_send_json_error('Sem permissão');$fields=array('ledimov_company_name','ledimov_company_type','ledimov_company_cnpj','ledimov_company_creci','ledimov_company_address','ledimov_company_city','ledimov_company_state','ledimov_company_phone','ledimov_company_whatsapp','ledimov_company_email','ledimov_company_website','ledimov_company_logo','ledimov_company_slogan','ledimov_company_about','ledimov_company_instagram','ledimov_company_facebook','ledimov_company_color_primary','ledimov_company_color_secondary');foreach($fields as $key){$post_key=str_replace('ledimov_company_','',$key);if(isset($_POST[$post_key]))update_option($key,sanitize_text_field($_POST[$post_key]));}if(isset($_POST['about']))update_option('ledimov_company_about',wp_kses_post($_POST['about']));wp_send_json_success('Configurações salvas!');}

add_action('wp_ajax_ledimov_create_page','ledimov_ajax_create_page');
function ledimov_ajax_create_page(){check_ajax_referer('ledimov_nonce','nonce');if(!current_user_can('manage_options'))wp_send_json_error('Acesso negado');$slug=sanitize_title($_POST['slug']??'');$title=sanitize_text_field($_POST['title']??'');$shortcode=wp_kses_post($_POST['shortcode']??'');if(!$slug||!$title||!$shortcode)wp_send_json_error('Dados insuficientes');$existing=get_page_by_path($slug);if($existing){wp_send_json_success(array('page_id'=>$existing->ID,'edit_url'=>get_edit_post_link($existing->ID,'raw'),'view_url'=>get_permalink($existing->ID),'existed'=>true));return;}$page_id=wp_insert_post(array('post_title'=>$title,'post_name'=>$slug,'post_content'=>$shortcode,'post_status'=>'publish','post_type'=>'page','post_author'=>get_current_user_id()));if(is_wp_error($page_id))wp_send_json_error($page_id->get_error_message());update_option('ledimov_page_'.$slug,$page_id);wp_send_json_success(array('page_id'=>$page_id,'edit_url'=>get_edit_post_link($page_id,'raw'),'view_url'=>get_permalink($page_id),'existed'=>false));}

/* ============================================================
   6. MENU ADMIN
   ============================================================ */
add_action('admin_menu','ledimov_admin_menu');
function ledimov_admin_menu(){
    add_menu_page('LedImov','LedImov 🏢','manage_options','ledimov','ledimov_admin_dashboard','dashicons-building',30);
    add_submenu_page('ledimov','Empreendimentos','Empreendimentos','manage_options','ledimov-properties','ledimov_admin_properties');
    add_submenu_page('ledimov','Unidades','Unidades','manage_options','ledimov-units','ledimov_admin_units');
    add_submenu_page('ledimov','Corretores','Corretores','manage_options','ledimov-brokers','ledimov_admin_brokers');
    add_submenu_page('ledimov','Vendas','Vendas','manage_options','ledimov-sales','ledimov_admin_sales');
    add_submenu_page('ledimov','Materiais','Materiais','manage_options','ledimov-materials','ledimov_admin_materials');
    add_submenu_page('ledimov','Criar Páginas','🔧 Criar Páginas','manage_options','ledimov-setup','ledimov_admin_setup');
    add_submenu_page('ledimov','Templates','🎨 Templates','manage_options','ledimov-templates','ledimov_admin_templates');
    add_submenu_page('ledimov','Editar Páginas','📝 Editar Páginas','manage_options','ledimov-pages','ledimov_admin_pages');
    add_submenu_page('ledimov','Exportar / Importar','📦 Export / Import','manage_options','ledimov-export-import','ledimov_admin_export_import');
    add_submenu_page('ledimov','Configurações','⚙️ Configurações','manage_options','ledimov-settings','ledimov_admin_settings');
}

/* ============================================================
   7. ADMIN – DASHBOARD
   ============================================================ */
function ledimov_admin_dashboard(){global $wpdb;$pt=$wpdb->prefix.'ledimov_properties';$ut=$wpdb->prefix.'ledimov_units';$bt=$wpdb->prefix.'ledimov_brokers';$total_prop=$wpdb->get_var("SELECT COUNT(*) FROM {$pt}");$total_units=$wpdb->get_var("SELECT COUNT(*) FROM {$ut}");$available=$wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE status='available'");$reserved=$wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE status='reserved'");$sold=$wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE status='sold'");$brokers=$wpdb->get_var("SELECT COUNT(*) FROM {$bt} WHERE status='active'");$vgv=$wpdb->get_var("SELECT SUM(price) FROM {$ut} WHERE status='sold'")?:0;?>
<div class="wrap ledimov-wrap">
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:24px;">
<h1 style="font-size:28px;font-weight:800;margin:0;">🏢 LedImov – Dashboard</h1>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
<button class="li-btn li-btn-outline" style="font-size:12px;" onclick="liRunMigration()" id="li-mig-btn">🩺 Verificar Banco</button>
<?php if(!get_option('ledimov_seeded_v1')): ?><button class="li-btn li-btn-outline" style="font-size:12px;border-color:#22c55e;color:#22c55e;" onclick="liRunSeed()" id="li-seed-btn">🌱 Criar Exemplo</button><?php endif; ?>
<a href="<?php echo admin_url('admin.php?page=ledimov-setup'); ?>" class="li-btn li-btn-primary" style="text-decoration:none;font-size:12px;">🔧 Criar Páginas</a>
</div></div>
<div id="li-mig-notice" style="display:none;background:#fef5f4;border:1px solid #c0392b;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#7b241c;"></div>
<script>
function liRunSeed(){var btn=document.getElementById('li-seed-btn'),box=document.getElementById('li-mig-notice');if(btn){btn.disabled=true;btn.textContent='⏳...';}liAjax('ledimov_run_seed',{},function(err,r){if(btn)btn.style.display='none';if(!box)return;box.style.display='block';box.style.borderColor='#22c55e';box.innerHTML='<span style="color:#22c55e">'+(err?'❌ Erro':(r.data.msg||'✅ Criado!'))+'</span>';});}
function liRunMigration(){var btn=document.getElementById('li-mig-btn'),box=document.getElementById('li-mig-notice');if(btn){btn.disabled=true;btn.textContent='⏳...';}liAjax('ledimov_force_migrate',{},function(err,r){if(btn){btn.disabled=false;btn.textContent='🩺 Verificar Banco';}if(!box)return;box.style.display='block';if(err||!r.success){box.innerHTML='<span style="color:var(--li-red)">❌ Erro</span>';return;}var d=r.data;box.style.borderColor=d.ok?'var(--li-green)':'var(--li-yellow)';box.innerHTML='<span style="color:'+(d.ok?'var(--li-green)':'var(--li-yellow)')+'">'+d.msg+'</span>';});}
</script>
<div class="li-stats-row">
<div class="li-stat-box"><div class="val"><?php echo $total_prop;?></div><div class="lbl">Empreendimentos</div></div>
<div class="li-stat-box"><div class="val"><?php echo $total_units;?></div><div class="lbl">Total de Unidades</div></div>
<div class="li-stat-box" style="border-color:#22c55e55;"><div class="val" style="color:var(--li-green)"><?php echo $available;?></div><div class="lbl">🟢 Disponíveis</div></div>
<div class="li-stat-box" style="border-color:#eab30855;"><div class="val" style="color:var(--li-yellow)"><?php echo $reserved;?></div><div class="lbl">🟡 Reservados</div></div>
<div class="li-stat-box" style="border-color:#ef444455;"><div class="val" style="color:var(--li-red)"><?php echo $sold;?></div><div class="lbl">🔴 Vendidos</div></div>
<div class="li-stat-box"><div class="val"><?php echo $brokers;?></div><div class="lbl">Corretores Ativos</div></div>
<div class="li-stat-box"><div class="val" style="color:var(--li-accent);font-size:18px;"><?php echo ledimov_money($vgv);?></div><div class="lbl">VGV Vendido</div></div>
</div>
<h2 style="font-size:18px;margin:28px 0 14px;">Empreendimentos</h2>
<?php $props=$wpdb->get_results("SELECT p.*,(SELECT COUNT(*) FROM {$ut} u WHERE u.property_id=p.id) as total_u,(SELECT COUNT(*) FROM {$ut} u WHERE u.property_id=p.id AND u.status='available') as avail_u,(SELECT COUNT(*) FROM {$ut} u WHERE u.property_id=p.id AND u.status='sold') as sold_u FROM {$pt} p ORDER BY p.id DESC");if($props):?>
<div class="li-grid">
<?php foreach($props as $p):$p_cover=isset($p->cover_url)?$p->cover_url:'';$p_title=isset($p->title)?$p->title:'';$p_addr=isset($p->address)?$p->address:'';?>
<div class="li-card"><div class="li-card-cover"><?php if($p_cover):?><img src="<?php echo esc_url($p_cover);?>" alt=""><?php else:?><span style="font-size:48px;">🏗</span><?php endif;?></div>
<div class="li-card-body"><div class="li-card-title"><?php echo esc_html($p_title);?></div><div class="li-card-addr"><?php echo esc_html($p_addr);?></div>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;"><span class="li-badge li-available"><?php echo intval($p->avail_u);?> Disp.</span><span class="li-badge li-sold"><?php echo intval($p->sold_u);?> Vend.</span><span class="li-badge" style="background:#1e293b;color:#94a3b8;"><?php echo intval($p->total_u);?> Total</span></div>
<div style="display:flex;gap:8px;"><a href="<?php echo admin_url('admin.php?page=ledimov-units&property='.$p->id);?>" class="li-btn li-btn-primary" style="text-decoration:none;font-size:12px;">📋 Unidades</a><a href="<?php echo admin_url('admin.php?page=ledimov-properties&edit='.$p->id);?>" class="li-btn li-btn-outline" style="text-decoration:none;font-size:12px;">✏️ Editar</a></div>
</div></div>
<?php endforeach;?>
</div>
<?php else:?><p style="color:var(--li-muted);">Nenhum empreendimento. <a href="<?php echo admin_url('admin.php?page=ledimov-properties&new=1');?>">Criar agora →</a></p><?php endif;?>
</div>
<?php }

/* ============================================================
   8. ADMIN – EMPREENDIMENTOS
   ============================================================ */
function ledimov_admin_properties(){global $wpdb;$t=$wpdb->prefix.'ledimov_properties';
if(isset($_GET['delete'])&&current_user_can('manage_options')){$wpdb->delete($t,array('id'=>intval($_GET['delete'])));echo '<div class="notice notice-success"><p>Excluído.</p></div>';}
$edit_id=intval($_GET['edit']??0);$is_new=isset($_GET['new']);$prop=null;if($edit_id)$prop=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$edit_id));
$pv=function($field,$default='')use($prop){if(!$prop)return $default;return isset($prop->$field)?$prop->$field:$default;};
?>
<div class="wrap ledimov-wrap">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
<h1 style="font-size:24px;font-weight:800;">🏗 Empreendimentos</h1>
<?php if(!$edit_id&&!$is_new):?><a href="?page=ledimov-properties&new=1" class="li-btn li-btn-primary">+ Novo Empreendimento</a><?php endif;?>
</div>
<?php if($edit_id||$is_new):?>
<div style="max-width:960px;">
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--li-border);">
<?php $tabs=['geral'=>['icon'=>'📋','label'=>'Dados Gerais'],'midia'=>['icon'=>'🖼','label'=>'Capa & Logo'],'galeria'=>['icon'=>'📷','label'=>'Galeria'],'plantas'=>['icon'=>'📐','label'=>'Plantas'],'landing'=>['icon'=>'📄','label'=>'Landing Page'],'localizacao'=>['icon'=>'📍','label'=>'Localização']];foreach($tabs as $tid=>$tab):$active=$tid==='geral';?>
<button type="button" class="li-prop-tab <?php echo $active?'active':'';?>" data-tab="<?php echo $tid;?>" onclick="liPropTab('<?php echo $tid;?>')" style="padding:10px 16px;border:none;background:none;font-family:var(--li-font);font-size:13px;font-weight:600;color:<?php echo $active?'var(--li-accent)':'var(--li-muted)';?>;border-bottom:2px solid <?php echo $active?'var(--li-accent)':'transparent';?>;margin-bottom:-2px;cursor:pointer;white-space:nowrap;"><?php echo $tab['icon'].' '.$tab['label'];?></button>
<?php endforeach;?>
</div>

<div class="li-prop-panel" id="li-tab-geral">
<div style="background:var(--li-card);padding:28px;border-radius:16px;border:1px solid var(--li-border);margin-bottom:20px;">
<h2 style="margin:0 0 20px;font-size:16px;font-weight:700;">📋 Informações Básicas</h2>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
<div class="li-form-group" style="grid-column:1/-1"><label>Nome do Empreendimento *</label><input class="li-input" id="prop-title" value="<?php echo esc_attr($pv('title'));?>" placeholder="Ex: Residencial Aurora"></div>
<div class="li-form-group"><label>Endereço</label><input class="li-input" id="prop-address" value="<?php echo esc_attr($pv('address'));?>"></div>
<div class="li-form-group"><label>Bairro</label><input class="li-input" id="prop-neighborhood" value="<?php echo esc_attr($pv('neighborhood'));?>"></div>
<div class="li-form-group"><label>Cidade</label><input class="li-input" id="prop-city" value="<?php echo esc_attr($pv('city'));?>"></div>
<div class="li-form-group"><label>Estado (UF)</label><input class="li-input" id="prop-state" value="<?php echo esc_attr($pv('state'));?>" placeholder="SP"></div>
<div class="li-form-group"><label>WhatsApp</label><input class="li-input" id="prop-whatsapp" value="<?php echo esc_attr($pv('whatsapp'));?>" placeholder="5511999999999"></div>
<div class="li-form-group"><label>Registro de Imóveis</label><input class="li-input" id="prop-legal_reg" value="<?php echo esc_attr($pv('legal_reg'));?>"></div>
<div class="li-form-group"><label>Previsão de Entrega</label><input class="li-input" id="prop-delivery_date" value="<?php echo esc_attr($pv('delivery_date'));?>" placeholder="Dezembro/2027"></div>
<div class="li-form-group"><label>Status</label><select class="li-input" id="prop-status"><option value="active" <?php selected($pv('status','active'),'active');?>>✅ Ativo</option><option value="inactive" <?php selected($pv('status','active'),'inactive');?>>🚫 Inativo</option></select></div>
<div class="li-form-group"><label>Badge / Etiqueta</label><input class="li-input" id="prop-badge_label" value="<?php echo esc_attr($pv('badge_label'));?>" placeholder="Lançamento"></div>
<div class="li-form-group" style="grid-column:1/-1"><label>Lazer & Amenidades (separadas por vírgula)</label><textarea class="li-input" id="prop-amenities" rows="3"><?php echo esc_textarea($pv('amenities'));?></textarea></div>
<div class="li-form-group" style="grid-column:1/-1"><label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;background:var(--li-card-2);border:1.5px solid var(--li-border);border-radius:var(--li-r);padding:12px 16px;width:100%;" id="prop-featured-lbl"><input type="checkbox" id="prop-featured" value="1" <?php checked(intval($pv('featured')),1);?> onchange="liUpdateFeaturedStyle()"><span><strong style="font-size:13px;">⭐ Exibir como Destaque</strong><span style="display:block;font-size:11px;color:var(--li-muted);font-weight:400;margin-top:2px;">Aparece no shortcode [ledimov_destaque]</span></span></label></div>
</div>
<div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
<button class="li-btn li-btn-primary" onclick="liSaveProperty(<?php echo $edit_id;?>)">💾 Salvar</button>
<a href="?page=ledimov-properties" class="li-btn li-btn-outline">Cancelar</a>
<?php if($edit_id):$prop_url=ledimov_property_url($prop);?><a href="<?php echo esc_url($prop_url);?>" target="_blank" class="li-btn li-btn-outline">🔗 Ver página</a><?php endif;?>
</div>
</div>
</div>

<div class="li-prop-panel" id="li-tab-midia" style="display:none;">
<div style="background:var(--li-card);padding:28px;border-radius:16px;border:1px solid var(--li-border);margin-bottom:20px;">
<h2 style="margin:0 0 20px;font-size:16px;font-weight:700;">🖼 Capa & Logo</h2>
<div class="li-form-group" style="margin-bottom:24px;"><label>Logo</label>
<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:8px;">
<div id="prop-logo-preview" style="width:120px;height:80px;background:var(--li-card-2);border-radius:10px;border:2px dashed var(--li-border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;"><?php $lv=$pv('logo_url');if($lv):?><img src="<?php echo esc_url($lv);?>" style="width:100%;height:100%;object-fit:contain;"><?php else:?><span style="font-size:28px;">🏷</span><?php endif;?></div>
<div style="flex:1;min-width:220px;">
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;"><button type="button" class="li-btn li-btn-primary" style="font-size:12px;" onclick="liPickImage('prop-logo_url','prop-logo-preview','contain')">📁 Biblioteca</button><button type="button" class="li-btn li-btn-outline" style="font-size:12px;" onclick="liPasteUrl('prop-logo_url','prop-logo-preview','contain')">🔗 URL</button></div>
<input type="text" class="li-input" id="prop-logo_url" value="<?php echo esc_attr($pv('logo_url'));?>" placeholder="URL do logo" style="font-size:11px;" oninput="liPreviewFromInput('prop-logo_url','prop-logo-preview','contain')">
</div></div></div>
<div class="li-form-group" style="margin-bottom:24px;"><label>Imagem de Capa</label>
<div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-top:8px;">
<div id="prop-cover-preview" style="width:280px;height:160px;background:var(--li-card-2);border-radius:10px;border:2px dashed var(--li-border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;"><?php $cv=$pv('cover_url');if($cv):?><img src="<?php echo esc_url($cv);?>" style="width:100%;height:100%;object-fit:cover;"><?php else:?><span style="font-size:40px;">🖼</span><?php endif;?></div>
<div style="flex:1;min-width:220px;">
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;"><button type="button" class="li-btn li-btn-primary" style="font-size:12px;" onclick="liPickImage('prop-cover_url','prop-cover-preview','cover')">📁 Biblioteca</button><button type="button" class="li-btn li-btn-outline" style="font-size:12px;" onclick="liPasteUrl('prop-cover_url','prop-cover-preview','cover')">🔗 URL</button></div>
<input type="text" class="li-input" id="prop-cover_url" value="<?php echo esc_attr($pv('cover_url'));?>" placeholder="URL da capa" style="font-size:11px;" oninput="liPreviewFromInput('prop-cover_url','prop-cover-preview','cover')">
</div></div></div>
<div class="li-form-group"><label>Vídeo (YouTube embed URL)</label><input class="li-input" id="prop-video_url" value="<?php echo esc_attr($pv('video_url'));?>" placeholder="https://www.youtube.com/embed/XXXXXXXXX"></div>
<div style="margin-top:20px;"><button class="li-btn li-btn-primary" onclick="liSaveProperty(<?php echo $edit_id;?>)">💾 Salvar</button></div>
</div></div>

<?php if($edit_id):$gt=$wpdb->prefix.'ledimov_gallery';?>
<div class="li-prop-panel" id="li-tab-galeria" style="display:none;">
<div style="background:var(--li-card);padding:28px;border-radius:16px;border:1px solid var(--li-border);margin-bottom:20px;">
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
<div><h2 style="font-size:16px;font-weight:700;margin:0 0 4px;">📷 Galeria de Fotos</h2><p style="color:var(--li-muted);font-size:12px;margin:0;">A primeira vira capa. Arraste para reordenar.</p></div>
<button type="button" class="li-btn li-btn-primary" onclick="liAddGalleryImages('property',<?php echo $edit_id;?>,'gallery')">+ Adicionar Fotos</button>
</div>
<div id="li-gallery-property-<?php echo $edit_id;?>-gallery" class="li-gallery-grid">
<?php $gallery_items=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$gt} WHERE ref_type='property' AND ref_id=%d AND (gallery_type='gallery' OR gallery_type IS NULL OR gallery_type='') ORDER BY sort_order ASC",$edit_id));foreach($gallery_items as $gi):?>
<div class="li-gallery-item" data-gid="<?php echo $gi->id;?>" draggable="true"><img src="<?php echo esc_url($gi->thumb_url?:$gi->url);?>" alt=""><?php if($gi->is_cover):?><span class="li-gallery-badge">⭐ Capa</span><?php endif;?><div class="li-gallery-actions"><button onclick="liDeleteGalleryItem(<?php echo $gi->id;?>,'property',<?php echo $edit_id;?>)" title="Remover">✕</button></div></div>
<?php endforeach;if(empty($gallery_items)):?><div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--li-muted);border:2px dashed var(--li-border);border-radius:10px;">📷 Nenhuma foto. Clique em "+ Adicionar Fotos".</div><?php endif;?>
</div>
<div style="margin-top:16px;"><button type="button" class="li-btn li-btn-outline" onclick="liSaveGalleryOrder('property',<?php echo $edit_id;?>,'gallery')">💾 Salvar Ordem</button></div>
</div></div>

<div class="li-prop-panel" id="li-tab-plantas" style="display:none;">
<div style="background:var(--li-card);padding:28px;border-radius:16px;border:1px solid var(--li-border);margin-bottom:20px;">
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:10px;"><div><h2 style="font-size:16px;font-weight:700;margin:0 0 4px;">📐 Plantas Baixas</h2></div><button type="button" class="li-btn li-btn-primary" onclick="liAddGalleryImages('property',<?php echo $edit_id;?>,'plant')">+ Adicionar Plantas</button></div>
<div id="li-gallery-property-<?php echo $edit_id;?>-plant" class="li-gallery-grid">
<?php $plant_items=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$gt} WHERE ref_type='property' AND ref_id=%d AND gallery_type='plant' ORDER BY sort_order ASC",$edit_id));foreach($plant_items as $gi):?>
<div class="li-gallery-item" data-gid="<?php echo $gi->id;?>" draggable="true"><img src="<?php echo esc_url($gi->thumb_url?:$gi->url);?>" alt=""><span class="li-gallery-badge" style="background:#0369a1;">📐</span><div class="li-gallery-actions"><button onclick="liDeleteGalleryItem(<?php echo $gi->id;?>,'property',<?php echo $edit_id;?>)" title="Remover">✕</button></div></div>
<?php endforeach;if(empty($plant_items)):?><div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--li-muted);border:2px dashed var(--li-border);border-radius:10px;">📐 Nenhuma planta.</div><?php endif;?>
</div>
<div style="margin-top:16px;"><button type="button" class="li-btn li-btn-outline" onclick="liSaveGalleryOrder('property',<?php echo $edit_id;?>,'plant')">💾 Salvar Ordem</button></div>
<div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--li-border);"><label class="li-form-group"><b>Texto da seção de Plantas</b></label><textarea class="li-input" id="prop-plant_text" rows="3"><?php echo esc_textarea($pv('plant_text'));?></textarea><button class="li-btn li-btn-primary" style="margin-top:10px;" onclick="liSaveProperty(<?php echo $edit_id;?>)">💾 Salvar texto</button></div>
</div></div>

<div class="li-prop-panel" id="li-tab-landing" style="display:none;">
<div style="background:var(--li-card);padding:28px;border-radius:16px;border:1px solid var(--li-border);margin-bottom:20px;">
<h2 style="margin:0 0 16px;font-size:16px;font-weight:700;">📄 Textos da Landing Page</h2>
<div style="display:grid;gap:16px;">
<div class="li-form-group"><label>Título Intro</label><input class="li-input" id="prop-intro_text" value="<?php echo esc_attr($pv('intro_text'));?>"></div>
<div class="li-form-group"><label>Descrição completa</label><textarea class="li-input" id="prop-description" rows="4"><?php echo esc_textarea($pv('description'));?></textarea></div>
<div class="li-form-group"><label>Texto da Ficha Técnica</label><textarea class="li-input" id="prop-ficha_text" rows="2"><?php echo esc_textarea($pv('ficha_text'));?></textarea></div>
</div>
<div style="margin-top:20px;"><button class="li-btn li-btn-primary" onclick="liSaveProperty(<?php echo $edit_id;?>)">💾 Salvar</button></div>
</div></div>

<div class="li-prop-panel" id="li-tab-localizacao" style="display:none;">
<div style="background:var(--li-card);padding:28px;border-radius:16px;border:1px solid var(--li-border);margin-bottom:20px;">
<h2 style="margin:0 0 16px;font-size:16px;font-weight:700;">📍 Mapa e Localização</h2>
<div class="li-form-group"><label>Texto sobre o bairro</label><textarea class="li-input" id="prop-location_text" rows="4"><?php echo esc_textarea($pv('location_text'));?></textarea></div>
<div class="li-form-group"><label>Tags de Proximidade (separadas por vírgula)</label><input class="li-input" id="prop-tags" value="<?php echo esc_attr($pv('tags'));?>" placeholder="Metrô a 300m, Shopping a 500m"></div>
<div class="li-form-group"><label>URL do Google Maps (embed)</label><input class="li-input" id="prop-google_maps_url" value="<?php echo esc_attr($pv('google_maps_url'));?>" placeholder="https://maps.google.com/maps?q=...&output=embed"><?php if($pv('google_maps_url')):?><div style="margin-top:10px;border-radius:10px;overflow:hidden;height:200px;"><iframe src="<?php echo esc_url($pv('google_maps_url'));?>" width="100%" height="200" frameborder="0" allowfullscreen loading="lazy" style="border:0;"></iframe></div><?php endif;?></div>
<div style="margin-top:20px;"><button class="li-btn li-btn-primary" onclick="liSaveProperty(<?php echo $edit_id;?>)">💾 Salvar</button></div>
</div></div>
<?php endif;// edit_id ?>

</div>
<script>
function liPropTab(tid){document.querySelectorAll('.li-prop-panel').forEach(function(p){p.style.display='none';});document.querySelectorAll('.li-prop-tab').forEach(function(b){var active=b.dataset.tab===tid;b.style.color=active?'var(--li-accent)':'var(--li-muted)';b.style.borderBottom=active?'2px solid var(--li-accent)':'2px solid transparent';if(active)b.classList.add('active');else b.classList.remove('active');});var panel=document.getElementById('li-tab-'+tid);if(panel)panel.style.display='block';}
function liUpdateFeaturedStyle(){var cb=document.getElementById('prop-featured'),lbl=document.getElementById('prop-featured-lbl');if(!cb||!lbl)return;lbl.style.borderColor=cb.checked?'var(--li-accent)':'var(--li-border)';lbl.style.background=cb.checked?'rgba(192,57,43,.07)':'var(--li-card-2)';}
document.addEventListener('DOMContentLoaded',liUpdateFeaturedStyle);setTimeout(liUpdateFeaturedStyle,100);
function liSaveProperty(id){var titleEl=document.getElementById('prop-title');if(!titleEl||!titleEl.value.trim())return liToast('O nome é obrigatório','warning');var fields=['title','address','neighborhood','city','state','description','amenities','legal_reg','whatsapp','logo_url','cover_url','video_url','location_text','badge_label','delivery_date','plant_text','ficha_text','intro_text','tags','google_maps_url','status'];var data={id:id};fields.forEach(function(f){var el=document.getElementById('prop-'+f);if(el)data[f]=el.value;});var featEl=document.getElementById('prop-featured');data.featured=featEl&&featEl.checked?1:0;var btn=event?event.target:null;if(btn){btn.disabled=true;btn.textContent='⏳ Salvando...';}liAjax('ledimov_save_property',data,function(err,res){if(btn){btn.disabled=false;btn.textContent='💾 Salvar';}if(err)return liToast('Erro de conexão','error');if(!res.success)return liToast('Erro: '+(res.data||'falha'),'error');liToast((res.data&&res.data.msg)||'Salvo!','success');if(!id&&res.data&&res.data.id)setTimeout(function(){location.href='?page=ledimov-properties&edit='+res.data.id;},800);});}
</script>

<?php else:
$props=$wpdb->get_results("SELECT * FROM {$t} ORDER BY featured DESC, featured_at DESC, id DESC");?>
<div class="li-excel-wrap"><table class="li-excel">
<thead><tr><th>ID</th><th>Empreendimento</th><th>Endereço</th><th>Cidade</th><th>Status</th><th style="text-align:center;">⭐</th><th>Ações</th></tr></thead>
<tbody>
<?php foreach($props as $p):$p_id=isset($p->id)?$p->id:0;$p_title=isset($p->title)?$p->title:'';$p_addr=isset($p->address)?$p->address:'';$p_city=isset($p->city)?$p->city:'';$p_state=isset($p->state)?$p->state:'';$p_st=isset($p->status)?$p->status:'active';$p_feat=isset($p->featured)?intval($p->featured):0;?>
<tr>
<td><?php echo intval($p_id);?></td><td><?php echo esc_html($p_title);?></td><td><?php echo esc_html($p_addr);?></td><td><?php echo esc_html(trim($p_city.', '.$p_state,', '));?></td>
<td><?php echo $p_st==='active'?'<span class="li-badge li-available">Ativo</span>':'<span class="li-badge li-blocked">Inativo</span>';?></td>
<td style="text-align:center;"><button id="feat-btn-<?php echo $p_id;?>" onclick="liToggleFeatured(<?php echo $p_id;?>)" style="background:none;border:none;cursor:pointer;font-size:22px;padding:4px;border-radius:6px;"><?php echo $p_feat?'⭐':'☆';?></button></td>
<td style="white-space:nowrap;"><a href="?page=ledimov-units&property=<?php echo intval($p_id);?>" class="li-btn li-btn-primary" style="font-size:11px;padding:5px 10px;text-decoration:none;">📋 Unidades</a> <a href="?page=ledimov-properties&edit=<?php echo intval($p_id);?>" class="li-btn li-btn-outline" style="font-size:11px;padding:5px 10px;text-decoration:none;">✏️ Editar</a> <a href="?page=ledimov-properties&delete=<?php echo intval($p_id);?>" class="li-btn" style="font-size:11px;padding:5px 10px;background:#fdecea;color:#c0392b;border:1px solid #f5b7b1;text-decoration:none;" onclick="return confirm('Excluir?')">🗑</a></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<script>function liToggleFeatured(id){var btn=document.getElementById('feat-btn-'+id);liAjax('ledimov_toggle_featured',{id:id},function(err,res){if(err||!res.success)return liToast((res&&res.data)||'Erro','error');var d=res.data;if(btn)btn.textContent=d.featured?'⭐':'☆';liToast(d.msg+(d.featured&&d.total?' ('+d.total+'/3 ativos)':''),d.featured?'success':'info');});}</script>
<?php endif;?>
</div>
<?php }

/* ============================================================
   9. ADMIN – UNIDADES
   ============================================================ */
function ledimov_admin_units(){global $wpdb;$ut=$wpdb->prefix.'ledimov_units';$pt=$wpdb->prefix.'ledimov_properties';$pid=intval($_GET['property']??0);$props=$wpdb->get_results("SELECT id, title FROM {$pt} ORDER BY title");$show_map=isset($_GET['view'])&&$_GET['view']==='map';?>
<div class="wrap ledimov-wrap">
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
<h1 style="font-size:24px;font-weight:800;">🏠 Estoque de Unidades</h1>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
<select id="li-prop-selector" class="li-filter-select" onchange="location.href='?page=ledimov-units&property='+this.value"><option value="">— Selecionar Empreendimento —</option><?php foreach($props as $pr):?><option value="<?php echo $pr->id;?>" <?php selected($pid,$pr->id);?>><?php echo esc_html($pr->title);?></option><?php endforeach;?></select>
<?php if($pid):?><a href="?page=ledimov-units&property=<?php echo $pid;?>" class="li-btn li-btn-outline" style="text-decoration:none;font-size:12px;">📋 Tabela</a><a href="?page=ledimov-units&property=<?php echo $pid;?>&view=map" class="li-btn li-btn-outline" style="text-decoration:none;font-size:12px;">🏗 Mapa</a><button class="li-btn li-btn-primary" onclick="liOpenAddUnit(<?php echo $pid;?>)" style="font-size:12px;">+ Unidade</button><?php endif;?>
</div></div>
<?php if(!$pid):?><p style="color:var(--li-muted);">Selecione um empreendimento acima.</p>
<?php elseif($show_map):?><?php ledimov_render_building_map($pid,true);?>
<?php else:
$units=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$ut} WHERE property_id=%d ORDER BY floor ASC, unit ASC",$pid));
$cnt=array('available'=>0,'reserved'=>0,'sold'=>0,'blocked'=>0);foreach($units as $u){$s=isset($u->status)?$u->status:'available';if(isset($cnt[$s]))$cnt[$s]++;}?>
<div class="li-stats-row" style="margin-bottom:16px;">
<div class="li-stat-box"><div class="val" style="color:var(--li-green)"><?php echo $cnt['available'];?></div><div class="lbl">🟢 Disponíveis</div></div>
<div class="li-stat-box"><div class="val" style="color:var(--li-yellow)"><?php echo $cnt['reserved'];?></div><div class="lbl">🟡 Reservados</div></div>
<div class="li-stat-box"><div class="val" style="color:var(--li-red)"><?php echo $cnt['sold'];?></div><div class="lbl">🔴 Vendidos</div></div>
<div class="li-stat-box"><div class="val"><?php echo count($units);?></div><div class="lbl">Total</div></div>
</div>
<div class="li-excel-wrap"><table class="li-excel">
<thead><tr><th>Torre</th><th>Andar</th><th>Apto</th><th>Qto</th><th>Área Útil</th><th>Entrada</th><th>Valor Total</th><th>Mensais</th><th>Status</th><th>Ações</th></tr></thead>
<tbody>
<?php foreach($units as $u):$u_id=isset($u->id)?$u->id:0;$u_tower=isset($u->tower)?$u->tower:'';$u_floor=isset($u->floor)?$u->floor:'';$u_unit=isset($u->unit)?$u->unit:'';$u_beds=isset($u->bedrooms)?$u->bedrooms:0;$u_autil=isset($u->area_util)?$u->area_util:'';$u_entry=isset($u->entry_price)?$u->entry_price:'';$u_price=isset($u->price)?$u->price:'';$u_mqty=isset($u->monthly_qty)?$u->monthly_qty:'';$u_mprc=isset($u->monthly_price)?$u->monthly_price:'';$u_stat=isset($u->status)?$u->status:'available';?>
<tr data-unit-id="<?php echo intval($u_id);?>" data-status="<?php echo esc_attr($u_stat);?>">
<td data-label="Torre"><input class="li-cell-edit" data-field="tower" value="<?php echo esc_attr($u_tower);?>"></td>
<td data-label="Andar"><input class="li-cell-edit" data-field="floor" value="<?php echo esc_attr($u_floor);?>" style="width:50px"></td>
<td data-label="Apto"><input class="li-cell-edit" data-field="unit" value="<?php echo esc_attr($u_unit);?>" style="width:60px"></td>
<td data-label="Qtos"><input class="li-cell-edit" data-field="bedrooms" value="<?php echo esc_attr($u_beds);?>" style="width:40px" type="number" min="0" max="9"></td>
<td data-label="Área"><input class="li-cell-edit" data-field="area_util" value="<?php echo esc_attr($u_autil);?>" style="width:70px"></td>
<td data-label="Entrada"><input class="li-cell-edit" data-field="entry_price" value="<?php echo esc_attr($u_entry);?>" style="width:100px"></td>
<td data-label="Valor"><input class="li-cell-edit" data-field="price" value="<?php echo esc_attr($u_price);?>" style="width:110px"></td>
<td data-label="Mensais" style="white-space:nowrap;"><input class="li-cell-edit" data-field="monthly_qty" value="<?php echo esc_attr($u_mqty);?>" style="width:36px">x<input class="li-cell-edit" data-field="monthly_price" value="<?php echo esc_attr($u_mprc);?>" style="width:90px"></td>
<td data-label="Status"><select class="li-select-status" data-field="status"><option value="available" <?php selected($u_stat,'available');?>>🟢 Disponível</option><option value="reserved" <?php selected($u_stat,'reserved');?>>🟡 Reservado</option><option value="sold" <?php selected($u_stat,'sold');?>>🔴 Vendido</option><option value="blocked" <?php selected($u_stat,'blocked');?>>⚪ Bloqueado</option></select></td>
<td style="white-space:nowrap;"><button class="li-btn li-btn-outline" style="font-size:11px;padding:4px 8px;" onclick="liOpenAddUnit(<?php echo $pid;?>,<?php echo intval($u_id);?>)">✏️</button> <button class="li-btn" style="font-size:11px;padding:4px 8px;background:#7f1d1d;color:#fca5a5;" onclick="liDeleteUnit(<?php echo intval($u_id);?>)">🗑</button></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</div>
<script>
function liDeleteUnit(id){if(!confirm('Excluir esta unidade?'))return;liAjax('ledimov_delete_unit',{id:id},function(err,res){if(err||!res.success)return liToast('Erro','error');liToast('Unidade excluída','success');setTimeout(function(){location.reload();},600);});}
function liOpenAddUnit(propId,editId){var u={};if(editId){var row=document.querySelector('tr[data-unit-id="'+editId+'"]');if(row){row.querySelectorAll('[data-field]').forEach(function(el){u[el.dataset.field]=el.value;});}}
var floorPlanThumb=u.floor_plan_url?'<img src="'+u.floor_plan_url+'" style="width:100%;height:100%;object-fit:contain;">':'<span style="color:#64748b;font-size:16px;">📐</span>';
var html='<h3 style="margin:0 0 16px;">'+(editId?'✏️ Editar Unidade':'+ Nova Unidade')+'</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div class="li-form-group"><label>Torre</label><input class="li-input" id="u-tower" value="'+(u.tower||'')+'"></div><div class="li-form-group"><label>Andar</label><input class="li-input" id="u-floor" type="number" value="'+(u.floor||'')+'"></div><div class="li-form-group"><label>Apto *</label><input class="li-input" id="u-unit" value="'+(u.unit||'')+'"></div><div class="li-form-group"><label>Final</label><input class="li-input" id="u-final" value="'+(u.final||'')+'"></div><div class="li-form-group"><label>Dormitórios</label><input class="li-input" id="u-bedrooms" type="number" value="'+(u.bedrooms||0)+'"></div><div class="li-form-group"><label>Banheiros</label><input class="li-input" id="u-bathrooms" type="number" value="'+(u.bathrooms||0)+'"></div><div class="li-form-group"><label>Área Útil</label><input class="li-input" id="u-area_util" value="'+(u.area_util||'')+'"></div><div class="li-form-group"><label>Área Total</label><input class="li-input" id="u-area_total" value="'+(u.area_total||'')+'"></div><div class="li-form-group"><label>Valor Total (R$)</label><input class="li-input" id="u-price" value="'+(u.price||'')+'"></div><div class="li-form-group"><label>Entrada (R$)</label><input class="li-input" id="u-entry_price" value="'+(u.entry_price||'')+'"></div><div class="li-form-group"><label>Qtd Mensais</label><input class="li-input" id="u-monthly_qty" type="number" value="'+(u.monthly_qty||0)+'"></div><div class="li-form-group"><label>Valor Mensal (R$)</label><input class="li-input" id="u-monthly_price" value="'+(u.monthly_price||'')+'"></div><div class="li-form-group"><label>Correção</label><input class="li-input" id="u-correction" value="'+(u.correction||'')+'"></div><div class="li-form-group"><label>Orientação</label><input class="li-input" id="u-orientation" value="'+(u.orientation||'')+'"></div><div class="li-form-group" style="grid-column:1/-1"><label>Status</label><select class="li-input" id="u-status"><option value="available">🟢 Disponível</option><option value="reserved">🟡 Reservado</option><option value="sold">🔴 Vendido</option><option value="blocked">⚪ Bloqueado</option></select></div><div class="li-form-group" style="grid-column:1/-1"><label>Planta Baixa</label><div style="display:flex;align-items:flex-start;gap:12px;margin-top:6px;flex-wrap:wrap;"><div id="u-floorplan-preview" style="width:100px;height:70px;background:#0f172a;border-radius:8px;border:2px dashed #334155;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">'+floorPlanThumb+'</div><div style="flex:1;min-width:180px;"><div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;"><button type="button" class="li-btn li-btn-primary" style="font-size:11px;" onclick="liPickImage(\'u-floor_plan_url\',\'u-floorplan-preview\',\'contain\')">📁 Biblioteca</button><button type="button" class="li-btn li-btn-outline" style="font-size:11px;" onclick="liPasteUrl(\'u-floor_plan_url\',\'u-floorplan-preview\',\'contain\')">🔗 URL</button></div><input type="text" class="li-input" id="u-floor_plan_url" value="'+(u.floor_plan_url||'')+'" style="font-size:11px;" oninput="liPreviewFromInput(\'u-floor_plan_url\',\'u-floorplan-preview\',\'contain\')"></div></div></div><div class="li-form-group" style="grid-column:1/-1"><label>Observações</label><textarea class="li-input" id="u-notes" rows="2">'+(u.notes||'')+'</textarea></div></div><div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;"><button class="li-btn li-btn-primary" onclick="liSubmitUnit('+propId+','+(editId||0)+')">💾 Salvar</button><button class="li-btn li-btn-outline" onclick="liModal.close()">Cancelar</button></div>';
liModal.open(html);if(editId&&u.status){document.getElementById('u-status').value=u.status;}}
function liSubmitUnit(propId,editId){var fields=['tower','floor','unit','final','bedrooms','bathrooms','area_util','area_total','price','entry_price','monthly_qty','monthly_price','correction','orientation','status','notes','floor_plan_url'];var data={property_id:propId,id:editId};fields.forEach(function(f){var el=document.getElementById('u-'+f);if(el)data[f]=el.value;});liAjax('ledimov_save_unit',data,function(err,res){if(err||!res.success)return liToast('Erro ao salvar','error');liToast('Unidade salva!','success');liModal.close();setTimeout(function(){location.reload();},600);});}
</script>
<?php }

/* ============================================================
   10. MAPA DO PRÉDIO
   ============================================================ */
function ledimov_render_building_map($property_id,$is_admin=false){global $wpdb;$ut=$wpdb->prefix.'ledimov_units';$broker=ledimov_current_broker();$show_all=$is_admin||$broker;$units=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$ut} WHERE property_id=%d ORDER BY floor DESC, unit ASC",$property_id));if(!$units){echo '<p style="color:var(--li-muted);">Nenhuma unidade cadastrada.</p>';return;}$floors=array();foreach($units as $u){if(!$show_all&&!in_array($u->status,array('available','reserved')))continue;$floors[$u->floor][]=$u;}krsort($floors);echo '<div class="li-legend"><span><div class="li-legend-dot" style="background:var(--li-green)"></div>Disponível</span><span><div class="li-legend-dot" style="background:var(--li-yellow)"></div>Reservado</span><span><div class="li-legend-dot" style="background:var(--li-red)"></div>Vendido</span><span><div class="li-legend-dot" style="background:#334155"></div>Bloqueado</span></div>';echo '<div class="li-building-map" data-poll-property="'.esc_attr($property_id).'">';foreach($floors as $floor=>$floor_units){echo '<div class="li-floor-row"><div class="li-floor-label">'.esc_html($floor).'º</div>';foreach($floor_units as $u){$cls=esc_attr($u->status);$tip='Apto '.$u->unit.' | '.ledimov_money($u->price);echo '<div class="li-unit-cell '.$cls.'" data-cell-unit="'.esc_attr($u->id).'" title="'.esc_attr($tip).'"';if($show_all)echo ' onclick="liShowUnitDetail('.$u->id.')"';echo '>'.$u->unit.'</div>';}echo '</div>';}echo '</div>';echo '<script>window.liShowUnitDetail=function(uid){liAjax("ledimov_get_unit_detail",{unit_id:uid},function(err,res){if(err||!res.success)return;var u=res.data;var badge={available:"🟢 Disponível",reserved:"🟡 Reservado",sold:"🔴 Vendido",blocked:"⚪ Bloqueado"};var planHtml="";if(u.floor_plan_url){planHtml="<div style=\'margin-bottom:14px;cursor:pointer;border-radius:10px;overflow:hidden;\' onclick=\'liLightbox.open([\\\""+u.floor_plan_url+"\\\"],0)\'><img src=\'"+u.floor_plan_url+"\' style=\'width:100%;max-height:200px;object-fit:contain;\' alt=\'Planta\'></div>";}var baseHtml=planHtml+"<h3>Apto "+u.unit+" – "+u.floor+"º Andar</h3><div style=\'margin-bottom:12px;\'>"+badge[u.status]+"</div><table style=\'width:100%;border-collapse:collapse;font-size:13px;\'><tr><td style=\'padding:6px 0;color:var(--li-muted)\'>Área Útil</td><td>"+u.area_util+" m²</td></tr><tr><td style=\'padding:6px 0;color:var(--li-muted)\'>Dormitórios</td><td>"+u.bedrooms+"</td></tr><tr><td style=\'padding:6px 0;color:var(--li-muted)\'>Valor Total</td><td style=\'font-weight:800;color:var(--li-green)\'>R$ "+parseFloat(u.price).toLocaleString("pt-BR",{minimumFractionDigits:2})+"</td></tr><tr><td style=\'padding:6px 0;color:var(--li-muted)\'>Entrada</td><td>R$ "+parseFloat(u.entry_price).toLocaleString("pt-BR",{minimumFractionDigits:2})+"</td></tr></table>";';if($show_all){echo 'var actHtml="<div style=\'margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;\'>"+(u.status==="available"?"<button class=\'li-btn li-btn-primary\' onclick=\'liReserve("+uid+")\'>⏱ Reservar</button>":"")+"<button class=\'li-btn li-btn-outline\' onclick=\'liGenerateProposal("+uid+")\'>📄 Proposta</button></div>";liModal.open(baseHtml+actHtml);';}else{echo 'liModal.open(baseHtml);';}echo '});};'.'</script>';}

/* ============================================================
   11. ADMIN – CORRETORES
   ============================================================ */
function ledimov_admin_brokers(){global $wpdb;$t=$wpdb->prefix.'ledimov_brokers';if(isset($_GET['delete'])&&current_user_can('manage_options'))$wpdb->delete($t,array('id'=>intval($_GET['delete'])));if(isset($_GET['approve'])&&current_user_can('manage_options')){$wpdb->update($t,array('status'=>'active'),array('id'=>intval($_GET['approve'])));echo '<div class="notice notice-success is-dismissible"><p>Corretor aprovado!</p></div>';}if(isset($_POST['ledimov_auto_approve'])&&current_user_can('manage_options')){update_option('ledimov_auto_approve_brokers',sanitize_text_field($_POST['ledimov_auto_approve']));echo '<div class="notice notice-success is-dismissible"><p>Configuração salva.</p></div>';}$auto_approve=get_option('ledimov_auto_approve_brokers','1');$pending_count=$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='pending'");?>
<div class="wrap ledimov-wrap">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;"><h1 style="font-size:24px;font-weight:800;">👤 Corretores</h1><button class="li-btn li-btn-primary" onclick="liOpenBrokerForm(0)">+ Novo Corretor</button></div>
<?php if($pending_count>0):?><div style="background:var(--li-card);border:1px solid var(--li-yellow);border-radius:10px;padding:14px 18px;margin-bottom:18px;"><strong style="color:var(--li-yellow);">⏳ <?php echo $pending_count;?> corretor(es) aguardando aprovação</strong></div><?php endif;?>
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;"><div style="flex:1;min-width:200px;"><div style="font-size:13px;font-weight:700;">⚙️ Aprovação de Novos Cadastros</div></div><form method="post" style="display:flex;align-items:center;gap:10px;"><select name="ledimov_auto_approve" class="li-input" style="font-size:13px;width:auto;"><option value="1" <?php selected($auto_approve,'1');?>>✅ Automático</option><option value="0" <?php selected($auto_approve,'0');?>>⏳ Manual</option></select><button type="submit" class="li-btn li-btn-outline" style="font-size:12px;">Salvar</button></form></div>
<?php $brokers=$wpdb->get_results("SELECT * FROM {$t} ORDER BY name");?>
<div class="li-excel-wrap"><table class="li-excel"><thead><tr><th>Nome</th><th>E-mail</th><th>Telefone</th><th>CRECI</th><th>Status</th><th>Ações</th></tr></thead><tbody>
<?php foreach($brokers as $b):$bv=function($f,$d='')use($b){return isset($b->$f)?$b->$f:$d;};$safe=array('id'=>intval($bv('id')),'name'=>$bv('name'),'email'=>$bv('email'),'phone'=>$bv('phone'),'creci'=>$bv('creci'),'agency'=>$bv('agency'),'status'=>$bv('status','pending'),'photo_url'=>$bv('photo_url'));?>
<tr><td><div style="display:flex;align-items:center;gap:8px;"><?php if($safe['photo_url']):?><img src="<?php echo esc_url($safe['photo_url']);?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;"><?php else:?><div style="width:30px;height:30px;border-radius:50%;background:var(--li-border);display:flex;align-items:center;justify-content:center;">👤</div><?php endif;?><strong><?php echo esc_html($safe['name']);?></strong></div></td>
<td><?php echo esc_html($safe['email']);?></td><td><?php echo esc_html($safe['phone']);?></td><td><?php echo esc_html($safe['creci']);?></td>
<td><?php $map=['active'=>'<span class="li-badge li-available">Ativo</span>','inactive'=>'<span class="li-badge li-blocked">Inativo</span>','pending'=>'<span class="li-badge li-reserved">Pendente</span>'];echo $map[$safe['status']]??esc_html($safe['status']);?></td>
<td style="white-space:nowrap;"><button class="li-btn li-btn-outline" style="font-size:11px;padding:4px 8px;" onclick="liOpenBrokerForm(<?php echo $safe['id'];?>,<?php echo htmlspecialchars(json_encode($safe),ENT_QUOTES);?>)">✏️ Editar</button><?php if($safe['status']==='pending'):?> <a href="?page=ledimov-brokers&approve=<?php echo $safe['id'];?>" class="li-btn" style="font-size:11px;padding:4px 8px;background:#14532d;color:#86efac;text-decoration:none;">✅ Aprovar</a><?php endif;?> <a href="?page=ledimov-brokers&delete=<?php echo $safe['id'];?>" class="li-btn" style="font-size:11px;padding:4px 8px;background:#7f1d1d;color:#fca5a5;text-decoration:none;" onclick="return confirm('Excluir?')">🗑</a></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>
<script>
function liOpenBrokerForm(id,data){data=data||{};var isNew=!id;var html='<h3 style="margin:0 0 16px;">'+(id?'✏️ Editar Corretor':'+ Novo Corretor')+'</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div class="li-form-group" style="grid-column:1/-1"><label>Nome *</label><input class="li-input" id="br-name" value="'+(data.name||'')+'"></div><div class="li-form-group" style="grid-column:1/-1"><label>E-mail *</label><input class="li-input" id="br-email" type="email" value="'+(data.email||'')+'"></div><div class="li-form-group"><label>Telefone</label><input class="li-input" id="br-phone" value="'+(data.phone||'')+'"></div><div class="li-form-group"><label>CRECI</label><input class="li-input" id="br-creci" value="'+(data.creci||'')+'"></div><div class="li-form-group" style="grid-column:1/-1"><label>Imobiliária</label><input class="li-input" id="br-agency" value="'+(data.agency||'')+'"></div><div class="li-form-group"><label>Status</label><select class="li-input" id="br-status"><option value="active">✅ Ativo</option><option value="inactive">🚫 Inativo</option><option value="pending">⏳ Pendente</option></select></div><div class="li-form-group"><label>Senha'+(isNew?' *':' (deixe em branco para manter)')+'</label><input class="li-input" id="br-password" type="password" placeholder="'+(isNew?'mínimo 6 caracteres':'nova senha (opcional)')+'"></div></div><div id="br-error" style="display:none;background:#7f1d1d;color:#fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;margin:12px 0;"></div><div style="margin-top:4px;display:flex;gap:10px;"><button class="li-btn li-btn-primary" id="br-save-btn" onclick="liSubmitBroker('+id+')">💾 Salvar Corretor</button><button class="li-btn li-btn-outline" onclick="liModal.close()">Cancelar</button></div>';
liModal.open(html);if(data.status)setTimeout(function(){var s=document.getElementById('br-status');if(s)s.value=data.status;},50);}
function liSubmitBroker(id){var errEl=document.getElementById('br-error'),btn=document.getElementById('br-save-btn');function showErr(msg){if(errEl){errEl.textContent=msg;errEl.style.display='block';}}if(errEl)errEl.style.display='none';var name=(document.getElementById('br-name')||{value:''}).value.trim(),email=(document.getElementById('br-email')||{value:''}).value.trim(),phone=(document.getElementById('br-phone')||{value:''}).value.trim(),creci=(document.getElementById('br-creci')||{value:''}).value.trim(),agency=(document.getElementById('br-agency')||{value:''}).value.trim(),status=(document.getElementById('br-status')||{value:'active'}).value,pass=(document.getElementById('br-password')||{value:''}).value;if(!name)return showErr('Nome é obrigatório');if(!email)return showErr('E-mail é obrigatório');if(!id&&!pass)return showErr('Senha é obrigatória');if(pass&&pass.length<6)return showErr('Senha deve ter pelo menos 6 caracteres');if(btn){btn.disabled=true;btn.textContent='⏳ Salvando...';}liAjax('ledimov_save_broker',{id:id,name:name,email:email,phone:phone,creci:creci,agency:agency,status:status,password:pass},function(err,res){if(btn){btn.disabled=false;btn.textContent='💾 Salvar Corretor';}if(err||!res.success)return showErr((res&&res.data)?res.data:'Erro.');liToast((res.data&&res.data.msg)||'Corretor salvo!','success');liModal.close();setTimeout(function(){location.reload();},700);});}
</script>
<?php }

/* ============================================================
   12-13. VENDAS + MATERIAIS (compactos)
   ============================================================ */
function ledimov_admin_sales(){global $wpdb;$st=$wpdb->prefix.'ledimov_sales';$ut=$wpdb->prefix.'ledimov_units';$bt=$wpdb->prefix.'ledimov_brokers';$pt=$wpdb->prefix.'ledimov_properties';$sales=$wpdb->get_results("SELECT s.*,u.unit,u.floor,u.price,p.title as prop_title,b.name as broker_name FROM {$st} s LEFT JOIN {$ut} u ON u.id=s.unit_id LEFT JOIN {$pt} p ON p.id=u.property_id LEFT JOIN {$bt} b ON b.id=s.broker_id ORDER BY s.created_at DESC LIMIT 200");$total_vgv=array_sum(array_column((array)$sales,'price_agreed'));?>
<div class="wrap ledimov-wrap"><h1 style="font-size:24px;font-weight:800;margin-bottom:20px;">💰 Vendas Realizadas</h1>
<div class="li-stats-row" style="margin-bottom:20px;"><div class="li-stat-box"><div class="val"><?php echo count($sales);?></div><div class="lbl">Vendas</div></div><div class="li-stat-box"><div class="val" style="font-size:16px;color:var(--li-green)"><?php echo ledimov_money($total_vgv);?></div><div class="lbl">VGV Total</div></div></div>
<div class="li-excel-wrap"><table class="li-excel"><thead><tr><th>#</th><th>Empreendimento</th><th>Unidade</th><th>Cliente</th><th>Corretor</th><th>Valor</th><th>Data</th></tr></thead><tbody>
<?php foreach($sales as $s):?><tr><td><?php echo $s->id;?></td><td><?php echo esc_html($s->prop_title);?></td><td>Apto <?php echo esc_html($s->unit);?> / <?php echo $s->floor;?>º</td><td><?php echo esc_html($s->client_name);?></td><td><?php echo esc_html($s->broker_name);?></td><td style="color:var(--li-green);font-weight:700"><?php echo ledimov_money($s->price_agreed);?></td><td style="color:var(--li-muted)"><?php echo date('d/m/Y',strtotime($s->created_at));?></td></tr><?php endforeach;?>
</tbody></table></div></div><?php }

function ledimov_admin_materials(){global $wpdb;$mt=$wpdb->prefix.'ledimov_materials';$pt=$wpdb->prefix.'ledimov_properties';if(isset($_POST['li_mat_save'])){check_admin_referer('ledimov_mat_save');$wpdb->insert($mt,array('property_id'=>intval($_POST['property_id']),'title'=>sanitize_text_field($_POST['title']),'file_url'=>esc_url_raw($_POST['file_url']),'type'=>sanitize_key($_POST['type']),'access'=>sanitize_key($_POST['access'])));}if(isset($_GET['delete_mat']))$wpdb->delete($mt,array('id'=>intval($_GET['delete_mat'])));$props=$wpdb->get_results("SELECT id,title FROM {$pt}");$mats=$wpdb->get_results("SELECT m.*,p.title as ptitle FROM {$mt} m LEFT JOIN {$pt} p ON p.id=m.property_id ORDER BY m.id DESC");?>
<div class="wrap ledimov-wrap"><h1 style="font-size:24px;font-weight:800;margin-bottom:20px;">📁 Materiais Técnicos</h1>
<div style="background:var(--li-card);padding:24px;border-radius:12px;border:1px solid var(--li-border);max-width:600px;margin-bottom:24px;">
<h3 style="margin:0 0 16px;">+ Adicionar Material</h3>
<form method="post"><?php wp_nonce_field('ledimov_mat_save');?><input type="hidden" name="li_mat_save" value="1">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
<div class="li-form-group"><label>Empreendimento</label><select class="li-input" name="property_id"><?php foreach($props as $p):?><option value="<?php echo $p->id;?>"><?php echo esc_html($p->title);?></option><?php endforeach;?></select></div>
<div class="li-form-group"><label>Tipo</label><select class="li-input" name="type"><option value="memorial">Memorial</option><option value="plant">Planta</option><option value="folder">Folder</option><option value="other">Outro</option></select></div>
<div class="li-form-group" style="grid-column:1/-1"><label>Título</label><input class="li-input" name="title" required></div>
<div class="li-form-group" style="grid-column:1/-1"><label>URL do Arquivo</label><input class="li-input" name="file_url" required></div>
<div class="li-form-group"><label>Acesso</label><select class="li-input" name="access"><option value="broker">Corretores</option><option value="public">Público</option><option value="admin">Admin</option></select></div>
</div><button type="submit" class="li-btn li-btn-primary" style="margin-top:12px;">💾 Salvar</button></form></div>
<div class="li-excel-wrap"><table class="li-excel"><thead><tr><th>Empreendimento</th><th>Título</th><th>Tipo</th><th>Acesso</th><th>Link</th><th>Ações</th></tr></thead><tbody>
<?php foreach($mats as $m):?><tr><td><?php echo esc_html($m->ptitle);?></td><td><?php echo esc_html($m->title);?></td><td><?php echo esc_html($m->type);?></td><td><?php echo esc_html($m->access);?></td><td><a href="<?php echo esc_url($m->file_url);?>" target="_blank">🔗 Abrir</a></td><td><a href="?page=ledimov-materials&delete_mat=<?php echo $m->id;?>" class="li-btn" style="font-size:11px;padding:4px 8px;background:#7f1d1d;color:#fca5a5;text-decoration:none;" onclick="return confirm('Excluir?')">🗑</a></td></tr><?php endforeach;?>
</tbody></table></div></div><?php }

/* ============================================================
   14. SHORTCODES
   ============================================================ */
add_shortcode('ledimov_tabela','ledimov_sc_tabela');
function ledimov_sc_tabela($atts){$atts=shortcode_atts(array('id'=>0,'property'=>0,'force'=>0),$atts);$pid=intval($atts['id']?:$atts['property']);if(!$pid)return '<p>Informe o id: [ledimov_tabela id="X"]</p>';$broker=ledimov_current_broker();$is_admin=ledimov_is_admin_user();if(!$broker&&!$is_admin&&!intval($atts['force']))return '';global $wpdb;$ut=$wpdb->prefix.'ledimov_units';$pt=$wpdb->prefix.'ledimov_properties';$prop=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d",$pid));if($broker||$is_admin)$units=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$ut} WHERE property_id=%d ORDER BY floor,unit",$pid));else $units=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$ut} WHERE property_id=%d AND status='available' ORDER BY floor,unit",$pid));$floors=array_unique(array_column((array)$units,'floor'));$bedrooms=array_unique(array_column((array)$units,'bedrooms'));sort($floors);sort($bedrooms);$_tpl=ledimov_get_tpl('tabela');if($_tpl)return ledimov_eval_tpl($_tpl,get_defined_vars());ob_start();?>
<div class="ledimov-wrap" id="ledimov-public-<?php echo $pid;?>">
<?php if($prop):?><div style="margin-bottom:20px;"><h2 style="font-size:24px;font-weight:800;"><?php echo esc_html(isset($prop->title)?$prop->title:'');?></h2><p style="color:var(--li-muted);margin:4px 0;"><?php echo esc_html(isset($prop->address)?$prop->address:'');?></p></div><?php endif;?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
<div class="li-filters" style="margin-bottom:0;flex:1;">
<select class="li-filter-select" id="li-flt-floor"><option value="">Todos os Andares</option><?php foreach($floors as $f):?><option value="<?php echo $f;?>"><?php echo $f;?>º Andar</option><?php endforeach;?></select>
<select class="li-filter-select" id="li-flt-beds"><option value="">Todos os Quartos</option><?php foreach($bedrooms as $b):if(!$b)continue;?><option value="<?php echo $b;?>"><?php echo $b;?> Dorm.</option><?php endforeach;?></select>
<input class="li-filter-select li-filter-input" id="li-flt-area" type="number" placeholder="Área mín. (m²)" style="width:140px;">
<input class="li-filter-select li-filter-input" id="li-flt-price" type="number" placeholder="Valor máx. (R$)" style="width:160px;">
</div>
<button class="li-btn li-btn-outline" style="font-size:12px;" onclick="liDownloadTablePDF(<?php echo $pid;?>,'<?php echo esc_js(isset($prop->title)?$prop->title:'');?>')">📥 Baixar PDF</button>
</div>
<div class="li-excel-wrap"><table class="li-excel">
<thead><tr><th>Apto</th><th>Andar</th><?php if($broker||$is_admin):?><th>Torre</th><?php endif;?><th>Dorm.</th><th>Área (m²)</th><th>Valor Total</th><th>Entrada</th><?php if($broker||$is_admin):?><th>Mensais</th><?php endif;?><th>Status</th><th>Ação</th></tr></thead>
<tbody>
<?php foreach($units as $u):$u_id=isset($u->id)?$u->id:0;$u_floor=isset($u->floor)?$u->floor:0;$u_beds=isset($u->bedrooms)?$u->bedrooms:0;$u_autil=isset($u->area_util)?$u->area_util:0;$u_price=isset($u->price)?$u->price:0;$u_unit=isset($u->unit)?$u->unit:'';$u_tower=isset($u->tower)?$u->tower:'';$u_entry=isset($u->entry_price)?$u->entry_price:0;$u_mqty=isset($u->monthly_qty)?$u->monthly_qty:0;$u_mprc=isset($u->monthly_price)?$u->monthly_price:0;$u_stat=isset($u->status)?$u->status:'available';$stat_map=array('available'=>'🟢 Disponível','reserved'=>'🟡 Reservado','sold'=>'🔴 Vendido','blocked'=>'⚪ Bloqueado');?>
<tr data-unit-row="1" data-floor="<?php echo esc_attr($u_floor);?>" data-bedrooms="<?php echo esc_attr($u_beds);?>" data-area="<?php echo esc_attr($u_autil);?>" data-price="<?php echo esc_attr($u_price);?>">
<td data-label="Apto"><strong>Apto <?php echo esc_html($u_unit);?></strong></td>
<td data-label="Andar"><?php echo intval($u_floor);?>º</td>
<?php if($broker||$is_admin):?><td data-label="Torre"><?php echo esc_html($u_tower);?></td><?php endif;?>
<td data-label="Dorm."><?php echo intval($u_beds);?></td>
<td data-label="Área"><?php echo number_format($u_autil,0,'.','.') ;?> m²</td>
<td data-label="Valor"><strong style="color:var(--li-green)"><?php echo ledimov_money($u_price);?></strong></td>
<td data-label="Entrada"><?php echo ledimov_money($u_entry);?></td>
<?php if($broker||$is_admin):?><td data-label="Mensais"><?php echo intval($u_mqty);?>x <?php echo ledimov_money($u_mprc);?></td><?php endif;?>
<td data-label="Status"><span class="li-status-badge"><?php echo isset($stat_map[$u_stat])?$stat_map[$u_stat]:$u_stat;?></span></td>
<td>
<?php if($u_stat==='available'&&($broker||$is_admin)):?><button class="li-btn li-btn-primary" style="font-size:11px;padding:5px 10px;" onclick="liReserve(<?php echo intval($u_id);?>)">⏱ Reservar</button><?php endif;?>
<?php if($broker||$is_admin):?><button class="li-btn li-btn-outline" style="font-size:11px;padding:5px 10px;" onclick="liGenerateProposal(<?php echo intval($u_id);?>)">📄 Proposta</button><?php endif;?>
<?php $prop_whats=isset($prop->whatsapp)?$prop->whatsapp:'';if(!$broker&&!$is_admin&&$prop&&$prop_whats&&$u_stat==='available'):?><a href="https://wa.me/<?php echo esc_attr($prop_whats);?>?text=Olá!+Interesse+no+Apto+<?php echo urlencode($u_unit);?>" target="_blank" class="li-btn li-btn-green" style="font-size:11px;padding:5px 10px;text-decoration:none;">💬 WhatsApp</a><?php endif;?>
</td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>
<?php return ob_get_clean();}
add_shortcode('ledimov_units','ledimov_sc_tabela');

/* [ledimov_mapa id="X"] */
add_shortcode('ledimov_mapa','ledimov_sc_mapa');
function ledimov_sc_mapa($atts){$atts=shortcode_atts(array('id'=>0,'property'=>0),$atts);$pid=intval($atts['id']?:$atts['property']);if(!$pid)return '';$_tpl=ledimov_get_tpl('mapa');if($_tpl)return ledimov_eval_tpl($_tpl,get_defined_vars());ob_start();echo '<div class="ledimov-wrap">';ledimov_render_building_map($pid,false);echo '</div>';return ob_get_clean();}

/* [ledimov_area_corretor] */
add_shortcode('ledimov_area_corretor','ledimov_sc_area_corretor');
function ledimov_sc_area_corretor($atts){$broker=ledimov_current_broker();$_tpl=ledimov_get_tpl('area_corretor');if($_tpl)return ledimov_eval_tpl($_tpl,get_defined_vars());ob_start();echo '<div class="ledimov-wrap li-portal">';
if(!$broker){?>
<div style="max-width:420px;margin:0 auto;padding:40px 0;">
<div style="text-align:center;margin-bottom:24px;"><div style="font-size:48px;margin-bottom:8px;">🏢</div><h2 style="font-size:24px;font-weight:800;">Área do Corretor</h2><p style="color:var(--li-muted);font-size:14px;">Portal exclusivo para parceiros credenciados</p></div>
<div style="display:flex;gap:0;border-bottom:2px solid var(--li-border);margin-bottom:24px;"><button id="li-tab-login" onclick="liShowTab('login')" style="flex:1;padding:10px;background:none;border:none;color:var(--li-text);font-size:14px;font-weight:700;cursor:pointer;border-bottom:3px solid var(--li-blue);margin-bottom:-2px;">🔑 Entrar</button><button id="li-tab-register" onclick="liShowTab('register')" style="flex:1;padding:10px;background:none;border:none;color:var(--li-muted);font-size:14px;font-weight:600;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;">✍️ Criar Conta</button></div>
<div id="li-panel-login">
<div class="li-form-group"><label>E-mail</label><input class="li-input" id="li-broker-email" type="email" placeholder="seu@email.com" autocomplete="email"></div>
<div class="li-form-group"><label>Senha</label><input class="li-input" id="li-broker-pass" type="password" placeholder="••••••••" autocomplete="current-password" onkeydown="if(event.key==='Enter')liBrokerLogin()"></div>
<div id="li-login-error" style="display:none;background:#7f1d1d;color:#fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;"></div>
<button class="li-btn li-btn-primary" id="li-login-btn" style="width:100%;padding:12px;font-size:15px;" onclick="liBrokerLogin()">Entrar →</button>
<p style="text-align:center;margin-top:14px;font-size:13px;color:var(--li-muted);">Sem conta? <a href="#" onclick="liShowTab('register');return false;" style="color:var(--li-blue);">Cadastre-se</a></p>
</div>
<div id="li-panel-register" style="display:none;">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
<div class="li-form-group" style="grid-column:1/-1"><label>Nome *</label><input class="li-input" id="li-reg-name" type="text" placeholder="João da Silva"></div>
<div class="li-form-group" style="grid-column:1/-1"><label>E-mail *</label><input class="li-input" id="li-reg-email" type="email" placeholder="seu@email.com"></div>
<div class="li-form-group"><label>Telefone</label><input class="li-input" id="li-reg-phone" type="tel" placeholder="(11) 99999-9999"></div>
<div class="li-form-group"><label>CRECI</label><input class="li-input" id="li-reg-creci" type="text"></div>
<div class="li-form-group" style="grid-column:1/-1"><label>Imobiliária</label><input class="li-input" id="li-reg-agency" type="text"></div>
<div class="li-form-group"><label>Senha * (mín. 6 car.)</label><input class="li-input" id="li-reg-pass" type="password"></div>
<div class="li-form-group"><label>Confirmar Senha *</label><input class="li-input" id="li-reg-pass2" type="password"></div>
</div>
<div id="li-reg-error" style="display:none;background:#7f1d1d;color:#fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;"></div>
<button class="li-btn li-btn-primary" id="li-reg-btn" style="width:100%;padding:12px;font-size:15px;" onclick="liBrokerRegister()">Criar Conta →</button>
</div>
</div>
<script>
function liShowTab(tab){var isLogin=(tab==='login');document.getElementById('li-panel-login').style.display=isLogin?'block':'none';document.getElementById('li-panel-register').style.display=isLogin?'none':'block';document.getElementById('li-tab-login').style.borderBottomColor=isLogin?'var(--li-blue)':'transparent';document.getElementById('li-tab-login').style.color=isLogin?'var(--li-text)':'var(--li-muted)';document.getElementById('li-tab-register').style.borderBottomColor=isLogin?'transparent':'var(--li-blue)';document.getElementById('li-tab-register').style.color=isLogin?'var(--li-muted)':'var(--li-text)';}
window.liBrokerRegister=function(){var name=(document.getElementById('li-reg-name')||{}).value||'',email=(document.getElementById('li-reg-email')||{}).value||'',phone=(document.getElementById('li-reg-phone')||{}).value||'',creci=(document.getElementById('li-reg-creci')||{}).value||'',agency=(document.getElementById('li-reg-agency')||{}).value||'',pass=(document.getElementById('li-reg-pass')||{}).value||'',pass2=(document.getElementById('li-reg-pass2')||{}).value||'';var errEl=document.getElementById('li-reg-error'),btn=document.getElementById('li-reg-btn');function showErr(msg){errEl.textContent=msg;errEl.style.display='block';}errEl.style.display='none';if(!name.trim())return showErr('Nome é obrigatório');if(!email.trim())return showErr('E-mail é obrigatório');if(pass.length<6)return showErr('Senha deve ter pelo menos 6 caracteres');if(pass!==pass2)return showErr('As senhas não coincidem');btn.disabled=true;btn.textContent='⏳ Criando conta...';liAjax('ledimov_broker_register',{name:name,email:email,phone:phone,creci:creci,agency:agency,password:pass},function(err,res){btn.disabled=false;btn.textContent='Criar Conta →';if(err||!res.success){return showErr(res?(res.data||'Erro'):'Erro de conexão');}if(res.data.auto_login){liToast(res.data.msg||'Bem-vindo(a)!','success');setTimeout(function(){if(res.data.redirect&&res.data.redirect!==window.location.href){location.href=res.data.redirect;}else{location.reload();}},1000);}else{errEl.style.background='#14532d';errEl.style.color='#86efac';errEl.textContent=res.data.msg||'Cadastro enviado!';errEl.style.display='block';btn.textContent='Cadastro Enviado ✅';}});};
</script>
<?php }else{
global $wpdb;$ut=$wpdb->prefix.'ledimov_units';$pt=$wpdb->prefix.'ledimov_properties';$rt=$wpdb->prefix.'ledimov_reservations';$mt=$wpdb->prefix.'ledimov_materials';$props=$wpdb->get_results("SELECT * FROM {$pt} WHERE status='active'");$my_reserv=$wpdb->get_results($wpdb->prepare("SELECT r.*,u.unit,u.floor,p.title as ptitle FROM {$rt} r LEFT JOIN {$ut} u ON u.id=r.unit_id LEFT JOIN {$pt} p ON p.id=u.property_id WHERE r.broker_id=%d AND r.status='active' ORDER BY r.expires_at ASC LIMIT 20",$broker->id));$avail_count=$wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE status='available'");$reserv_count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rt} WHERE broker_id=%d AND status='active'",$broker->id));?>
<div class="li-portal-header"><div><div style="font-size:11px;color:var(--li-muted);">Bem-vindo,</div><div style="font-size:20px;font-weight:800;"><?php echo esc_html($broker->name);?></div><?php if($broker->creci):?><div style="font-size:12px;color:var(--li-muted);">CRECI <?php echo esc_html($broker->creci);?></div><?php endif;?></div><button class="li-btn li-btn-outline" style="font-size:12px;" onclick="liAjax('ledimov_broker_logout',{},function(){location.reload();})">Sair →</button></div>
<div class="li-stats-row"><div class="li-stat-box"><div class="val" style="color:var(--li-green)"><?php echo $avail_count;?></div><div class="lbl">🟢 Disponíveis</div></div><div class="li-stat-box"><div class="val" style="color:var(--li-yellow)"><?php echo $reserv_count;?></div><div class="lbl">⏱ Minhas Reservas</div></div></div>
<?php if($my_reserv):?><h3 style="margin:20px 0 12px;">⏱ Minhas Reservas Ativas</h3><div class="li-excel-wrap" style="margin-bottom:24px;"><table class="li-excel"><thead><tr><th>Empreend.</th><th>Unidade</th><th>Cliente</th><th>Expira em</th></tr></thead><tbody><?php foreach($my_reserv as $r):$diff=strtotime($r->expires_at)-time();$mins=max(0,floor($diff/60));?><tr><td><?php echo esc_html($r->ptitle);?></td><td>Apto <?php echo esc_html($r->unit);?> / <?php echo $r->floor;?>º</td><td><?php echo esc_html($r->client_name);?></td><td style="color:<?php echo $mins<10?'var(--li-red)':'var(--li-yellow)';?>"><?php echo $mins;?> min</td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
<h3 style="margin:0 0 12px;">🏢 Estoque por Empreendimento</h3>
<?php foreach($props as $p):$p_id=isset($p->id)?$p->id:0;$p_title=isset($p->title)?$p->title:'';$p_addr=isset($p->address)?$p->address:'';?>
<div style="background:var(--li-card);border-radius:12px;border:1px solid var(--li-border);padding:20px;margin-bottom:20px;"><div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;"><div><div style="font-size:16px;font-weight:700;"><?php echo esc_html($p_title);?></div><div style="font-size:12px;color:var(--li-muted)"><?php echo esc_html($p_addr);?></div></div><div style="display:flex;gap:8px;"><button class="li-btn li-btn-outline" style="font-size:12px;" onclick="liToggleMap('map-<?php echo intval($p_id);?>')">🏗 Mapa</button><button class="li-btn li-btn-outline" style="font-size:12px;" onclick="liToggleMap('table-<?php echo intval($p_id);?>')">📋 Tabela</button></div></div>
<div id="map-<?php echo intval($p_id);?>" style="display:none;margin-top:16px;"><?php ledimov_render_building_map($p_id,false);?></div>
<div id="table-<?php echo intval($p_id);?>" style="display:block;margin-top:16px;"><?php echo ledimov_sc_tabela(array('id'=>$p_id));?></div>
<?php $mats=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$mt} WHERE property_id=%d AND access IN ('broker','public')",$p_id));if($mats):?><div style="margin-top:12px;"><div style="font-size:12px;font-weight:600;color:var(--li-muted);margin-bottom:8px;">📁 Materiais</div><div style="display:flex;gap:8px;flex-wrap:wrap;"><?php foreach($mats as $m):?><a href="<?php echo esc_url(isset($m->file_url)?$m->file_url:'');?>" target="_blank" class="li-btn li-btn-outline" style="font-size:11px;padding:5px 10px;text-decoration:none;">⬇️ <?php echo esc_html(isset($m->title)?$m->title:'');?></a><?php endforeach;?></div></div><?php endif;?>
</div>
<?php endforeach;?>
<?php }
echo '</div>';
echo '<script>function liToggleMap(id){var el=document.getElementById(id);if(el)el.style.display=el.style.display===\'none\'?\'block\':\'none\';}</script>';
return ob_get_clean();}

/* [ledimov_vitrine] */
add_shortcode('ledimov_vitrine','ledimov_sc_vitrine');
function ledimov_sc_vitrine($atts){global $wpdb;$pt=$wpdb->prefix.'ledimov_properties';$ut=$wpdb->prefix.'ledimov_units';$props=$wpdb->get_results("SELECT * FROM {$pt} WHERE status='active' ORDER BY title");$_tpl=ledimov_get_tpl('vitrine');if($_tpl)return ledimov_eval_tpl($_tpl,get_defined_vars());ob_start();?>
<div class="ledimov-wrap"><div class="li-grid">
<?php foreach($props as $p):$p_id=isset($p->id)?$p->id:0;$p_cover=isset($p->cover_url)?$p->cover_url:'';$p_title=isset($p->title)?$p->title:'';$p_addr=isset($p->address)?$p->address:'';$p_amen=isset($p->amenities)?$p->amenities:'';$min=$wpdb->get_var($wpdb->prepare("SELECT MIN(price) FROM {$ut} WHERE property_id=%d AND status='available'",$p_id));$av=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ut} WHERE property_id=%d AND status='available'",$p_id));$p_url=ledimov_property_url($p);?>
<a href="<?php echo esc_url($p_url);?>" class="li-card" style="text-decoration:none;color:inherit;display:block;">
<div class="li-card-cover"><?php if($p_cover):?><img src="<?php echo esc_url($p_cover);?>" alt=""><?php else:?><span style="font-size:56px;">🏢</span><?php endif;?></div>
<div class="li-card-body"><div class="li-card-title"><?php echo esc_html($p_title);?></div><div class="li-card-addr">📍 <?php echo esc_html($p_addr);?></div><?php if($p_amen):?><div style="font-size:12px;color:var(--li-muted);margin-bottom:8px;">🏊 <?php echo esc_html($p_amen);?></div><?php endif;?><?php if($min):?><div class="li-card-price" style="margin-bottom:8px;"><?php echo ledimov_money($min);?></div><?php endif;?><span class="li-badge li-available"><?php echo intval($av);?> unidades disp.</span><div style="margin-top:12px;"><span class="li-btn li-btn-primary" style="font-size:12px;pointer-events:none;">Ver Empreendimento →</span></div></div>
</a>
<?php endforeach;?>
</div></div>
<?php return ob_get_clean();}

/* [ledimov_destaque] */
add_shortcode('ledimov_destaque','ledimov_sc_destaque');
function ledimov_sc_destaque($atts){$atts=shortcode_atts(array('titulo'=>'','subtitulo'=>'','link'=>''),$atts);global $wpdb;$pt=$wpdb->prefix.'ledimov_properties';$ut=$wpdb->prefix.'ledimov_units';$props=$wpdb->get_results("SELECT * FROM {$pt} WHERE featured=1 AND status='active' ORDER BY featured_at DESC LIMIT 3");if(empty($props))return '<p style="font-family:\'Montserrat\',sans-serif;color:#888;padding:24px 0;">Nenhum empreendimento em destaque.</p>';
$_tpl=ledimov_get_tpl('destaque');if($_tpl)return ledimov_eval_tpl($_tpl,get_defined_vars());
ob_start();?>
<div class="ledimov-wrap" style="background:transparent;padding:0;">
<?php if($atts['titulo']||$atts['subtitulo']):?><div style="text-align:center;margin-bottom:32px;"><?php if($atts['titulo']):?><h2 style="font-family:'Montserrat',sans-serif;font-size:28px;font-weight:800;margin:0 0 8px;color:var(--li-text);"><?php echo esc_html($atts['titulo']);?></h2><?php endif;?><?php if($atts['subtitulo']):?><p style="font-family:'Montserrat',sans-serif;font-size:15px;color:var(--li-muted);margin:0;"><?php echo esc_html($atts['subtitulo']);?></p><?php endif;?></div><?php endif;?>
<div class="li-destaque-grid">
<?php foreach($props as $p):
$p_id=isset($p->id)?$p->id:0;$p_cover=isset($p->cover_url)?$p->cover_url:'';$p_title=isset($p->title)?$p->title:'';$p_city=isset($p->city)?$p->city:'';$p_state=isset($p->state)?$p->state:'';$p_desc=isset($p->description)?$p->description:'';$p_whats=isset($p->whatsapp)?$p->whatsapp:'';
$beds_min=$wpdb->get_var($wpdb->prepare("SELECT MIN(bedrooms) FROM {$ut} WHERE property_id=%d AND bedrooms>0",$p_id));$beds_max=$wpdb->get_var($wpdb->prepare("SELECT MAX(bedrooms) FROM {$ut} WHERE property_id=%d AND bedrooms>0",$p_id));$area_min=$wpdb->get_var($wpdb->prepare("SELECT MIN(area_util) FROM {$ut} WHERE property_id=%d AND area_util>0",$p_id));$area_max=$wpdb->get_var($wpdb->prepare("SELECT MAX(area_util) FROM {$ut} WHERE property_id=%d AND area_util>0",$p_id));$avail=intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ut} WHERE property_id=%d AND status='available'",$p_id)));
$beds_str=($beds_min&&$beds_max)?($beds_min==$beds_max?"{$beds_min} dorm.":"{$beds_min}-{$beds_max} dorm."):'';$a1=number_format((float)$area_min,0,'.','.'); $a2=number_format((float)$area_max,0,'.','.'); $area_str=($area_min&&$area_max)?($area_min==$area_max?"{$a1} m²":"{$a1} m² a {$a2} m²"):'';
$badge_text='';if(stripos($p_desc,'pronto')!==false)$badge_text='Pronto para morar';elseif(stripos($p_desc,'lançamento')!==false||stripos($p_desc,'lancamento')!==false)$badge_text='Lançamento';elseif(stripos($p_desc,'entrega')!==false)$badge_text='Em entrega';elseif($avail>0)$badge_text='Disponível';
$subtitle=$p_desc?wp_trim_words($p_desc,10,''):'';
$prop_page_url=ledimov_property_url($p);
$cta_url=$prop_page_url?:'';
if(!$cta_url&&$p_whats)$cta_url='https://wa.me/'.esc_attr($p_whats).'?text='.urlencode('Olá! Gostaria de saber mais sobre '.$p_title);
$loc=trim($p_city.($p_state?' - '.$p_state:''),' -');
?>
<a href="<?php echo esc_url($prop_page_url?:($cta_url?:'#'));?>" class="li-destaque-card">
  <div class="li-destaque-cover">
    <?php if($p_cover):?><img src="<?php echo esc_url($p_cover);?>" alt="<?php echo esc_attr($p_title);?>"><?php else:?><div class="li-destaque-cover-placeholder">🏢</div><?php endif;?>
    <?php if($badge_text):?><span class="li-destaque-badge"><?php echo esc_html($badge_text);?></span><?php endif;?>
  </div>
  <div class="li-destaque-body">
    <h3 class="li-destaque-title"><?php echo esc_html($p_title);?></h3>
    <?php if($subtitle):?><p class="li-destaque-subtitle"><?php echo esc_html($subtitle);?></p><?php endif;?>
    <?php if($beds_str||$area_str):?>
    <div class="li-destaque-infos">
      <?php if($beds_str):?><div class="li-destaque-info-item"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9V4a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v5"/><path d="M2 20v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5"/><path d="M2 13h20"/><path d="M7 13v-3a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v3"/></svg><?php echo esc_html($beds_str);?></div><?php endif;?>
      <?php if($area_str):?><div class="li-destaque-info-item"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg><?php echo esc_html($area_str);?></div><?php endif;?>
    </div>
    <?php endif;?>
    <div class="li-destaque-footer">
      <?php if($loc):?><span class="li-destaque-loc"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><?php echo esc_html($loc);?></span><?php endif;?>
      <?php if($cta_url):?><span class="li-destaque-cta">Saiba mais →</span><?php else:?><span class="li-destaque-cta" style="opacity:.4;">Saiba mais →</span><?php endif;?>
    </div>
  </div>
</a>
<?php endforeach;?>
</div></div>
<?php return ob_get_clean();}

/* [ledimov_card_imovel id="X"] */
add_shortcode('ledimov_card_imovel','ledimov_sc_card_imovel');
function ledimov_sc_card_imovel($atts){
    $atts=shortcode_atts(array('id'=>0),$atts);
    $pid=intval($atts['id']);
    if(!$pid)return '<p style="font-family:\'Montserrat\',sans-serif;">Informe o id: [ledimov_card_imovel id="X"]</p>';
    global $wpdb;
    $pt=$wpdb->prefix.'ledimov_properties';
    $ut=$wpdb->prefix.'ledimov_units';
    $prop=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND status='active'",$pid));
    if(!$prop)return '<p style="font-family:\'Montserrat\',sans-serif;">Empreendimento não encontrado.</p>';
    $co=ledimov_get_company();
    $broker=ledimov_current_broker();
    $is_admin=ledimov_is_admin_user();
    $is_logged=$broker||$is_admin;
    $pv=function($f,$d='')use($prop){return isset($prop->$f)&&$prop->$f!==''?$prop->$f:$d;};
    $c_title    =$pv('title');
    $c_cover    =$pv('cover_url');
    $c_city     =$pv('city');
    $c_state    =$pv('state');
    $c_neigh    =$pv('neighborhood');
    $c_addr     =trim(implode(', ',array_filter([$pv('address'),$c_neigh,$c_city.($c_state?' – '.$c_state:'')])),', ');
    $c_desc     =$pv('description');
    $c_amen     =$pv('amenities');
    $c_whats    =$pv('whatsapp',$co['whatsapp']);
    $c_delivery =$pv('delivery_date');
    $c_legal    =$pv('legal_reg');
    $c_video    =$pv('video_url');
    $c_loc_text =$pv('location_text');
    $c_maps     =$pv('google_maps_url');
    $c_tags     =$pv('tags');
    $c_plant_text=$pv('plant_text');
    $c_ficha    =$pv('ficha_text');
    $c_logo     =$pv('logo_url',$co['logo_url']);
    $c_badge    =$pv('badge_label','');
    $c_color    =$co['color_primary']?:'#c0392b';
    // darken accent for hover
    $c_dark     =$co['color_secondary']?:'#0e9f6e';
    $co_name    =$co['name']??get_bloginfo('name');

    $gallery      =ledimov_get_gallery('property',$pid);
    $plants_gallery=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ledimov_gallery WHERE ref_type='property' AND ref_id=%d AND gallery_type='plant' ORDER BY sort_order ASC",$pid));

    $beds_min =$wpdb->get_var($wpdb->prepare("SELECT MIN(bedrooms) FROM {$ut} WHERE property_id=%d AND bedrooms>0",$pid));
    $beds_max =$wpdb->get_var($wpdb->prepare("SELECT MAX(bedrooms) FROM {$ut} WHERE property_id=%d AND bedrooms>0",$pid));
    $area_min =$wpdb->get_var($wpdb->prepare("SELECT MIN(area_util) FROM {$ut} WHERE property_id=%d AND area_util>0",$pid));
    $area_max =$wpdb->get_var($wpdb->prepare("SELECT MAX(area_util) FROM {$ut} WHERE property_id=%d AND area_util>0",$pid));
    $price_min=$wpdb->get_var($wpdb->prepare("SELECT MIN(price) FROM {$ut} WHERE property_id=%d AND price>0 AND status='available'",$pid));
    $avail    =intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ut} WHERE property_id=%d AND status='available'",$pid)));

    $beds_str =($beds_min&&$beds_max)?($beds_min==$beds_max?"{$beds_min} dorm.":"{$beds_min}–{$beds_max} dorm."):'';
    $a1=number_format((float)$area_min,0,'.','.'); $a2=number_format((float)$area_max,0,'.','.'); $area_str=($area_min&&$area_max)?($area_min==$area_max?"{$a1} m²":"{$a1}–{$a2} m²"):'';
    $amen_arr =array_filter(array_map('trim',$c_amen?explode(',',$c_amen):[]));
    $tags_arr =array_filter(array_map('trim',$c_tags?explode(',',$c_tags):[]));

    $gallery_urls=[];
    foreach($gallery as $gi) if(!empty($gi->url)) $gallery_urls[]=esc_url($gi->url);
    $plant_urls=[];
    foreach($plants_gallery as $gi) if(!empty($gi->url)) $plant_urls[]=esc_url($gi->url);

    $_tpl=ledimov_get_tpl('card_imovel');if($_tpl)return ledimov_eval_tpl($_tpl,get_defined_vars());
    ob_start();
?>
<style>
/* ── LedImov Landing Page ────────────────────────────────── */
.li-lp {
  --lp-accent: <?php echo esc_attr($c_color);?>;
  --lp-dark: #0d0d1a;
  --lp-text: #1a1a2e;
  --lp-muted: #6b7280;
  --lp-border: #e5e7eb;
  --lp-surface: #f9fafb;
  font-family: 'Montserrat', sans-serif;
  background: #fff;
  color: var(--lp-text);
  overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
}
.li-lp *, .li-lp *::before, .li-lp *::after {
  box-sizing: border-box;
  font-family: 'Montserrat', sans-serif;
}

/* Nav */
.li-lp-nav {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  padding: 20px 40px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 10;
  background: linear-gradient(to bottom, rgba(0, 0, 0, 0.45) 0%, transparent 100%);
}
.li-lp-nav-logo {
  height: 38px;
  object-fit: contain;
  filter: brightness(0) invert(1);
  opacity: 0.92;
}
.li-lp-nav-brand {
  font-size: 13px;
  font-weight: 800;
  color: #fff;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  opacity: 0.85;
}
.li-lp-nav-cta {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  background: var(--lp-accent);
  color: #fff;
  text-decoration: none;
  font-size: 12px;
  font-weight: 700;
  padding: 10px 22px;
  border-radius: 999px;
  letter-spacing: 0.04em;
  transition: opacity 0.2s, transform 0.15s;
  white-space: nowrap;
}
.li-lp-nav-cta:hover {
  opacity: 0.88;
  transform: scale(1.03);
}

/* Hero */
.li-lp-hero {
  position: relative;
  min-height: 92vh;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  overflow: hidden;
  background: var(--lp-dark);
}
.li-lp-hero-bg {
  position: absolute;
  inset: 0;
  background-size: cover;
  background-position: center;
  opacity: 0.52;
  transition: transform 8s ease;
}
.li-lp-hero:hover .li-lp-hero-bg {
  transform: scale(1.03);
}
.li-lp-hero-grad {
  position: absolute;
  inset: 0;
  background: linear-gradient(165deg, rgba(0, 0, 0, 0.05) 0%, rgba(0, 0, 0, 0.55) 55%, rgba(0, 0, 0, 0.92) 100%);
}
.li-lp-hero-body {
  position: relative;
  z-index: 2;
  padding: clamp(32px, 6vw, 80px) clamp(20px, 5vw, 64px) clamp(56px, 8vw, 100px);
  max-width: 900px;
}
.li-lp-badge-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--lp-accent);
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 6px 16px;
  border-radius: 999px;
  margin-bottom: 18px;
}
.li-lp-h1 {
  font-size: clamp(32px, 6vw, 64px);
  font-weight: 900;
  color: #fff;
  margin: 0 0 14px;
  letter-spacing: -0.03em;
  line-height: 1.05;
}
.li-lp-loc {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: rgba(255, 255, 255, 0.7);
  font-size: 14px;
  font-weight: 500;
  margin-bottom: 24px;
}
.li-lp-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.li-lp-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.22);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  padding: 8px 16px;
  border-radius: 999px;
  backdrop-filter: blur(8px);
  letter-spacing: 0.02em;
  transition: transform 0.2s;
}
.li-lp-pill:hover {
  transform: scale(1.05);
}
.li-lp-pill-green {
  background: rgba(34, 197, 94, 0.15);
  border-color: rgba(34, 197, 94, 0.35);
  color: #86efac;
}

/* CTA Bar */
.li-lp-ctabar {
  background: var(--lp-accent);
  padding: 24px clamp(20px, 5vw, 64px);
}
.li-lp-ctabar-inner {
  max-width: 1100px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}
.li-lp-ctabar-text {
  flex: 1;
  min-width: 180px;
}
.li-lp-ctabar-title {
  font-size: 17px;
  font-weight: 800;
  color: #fff;
  margin-bottom: 2px;
}
.li-lp-ctabar-sub {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.72);
}
.li-lp-ctabar-fields {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  flex: 2;
  min-width: 280px;
}
.li-lp-field {
  flex: 1;
  min-width: 120px;
  background: rgba(255, 255, 255, 0.14);
  border: 1.5px solid rgba(255, 255, 255, 0.28);
  color: #fff;
  font-size: 13px;
  font-weight: 500;
  font-family: 'Montserrat', sans-serif;
  padding: 11px 14px;
  border-radius: 10px;
  outline: none;
  transition: border-color 0.2s;
}
.li-lp-field::placeholder {
  color: rgba(255, 255, 255, 0.55);
}
.li-lp-field:focus {
  border-color: rgba(255, 255, 255, 0.7);
}
.li-lp-btn-white {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  background: #fff;
  color: var(--lp-accent);
  border: none;
  cursor: pointer;
  font-family: 'Montserrat', sans-serif;
  font-size: 13px;
  font-weight: 800;
  padding: 12px 24px;
  border-radius: 10px;
  white-space: nowrap;
  transition: opacity 0.15s, transform 0.15s;
}
.li-lp-btn-white:hover {
  opacity: 0.9;
  transform: scale(1.02);
}

/* Section Layout */
.li-lp-section {
  padding: clamp(48px, 7vw, 96px) clamp(20px, 5vw, 64px);
}
.li-lp-section-inner {
  max-width: 1100px;
  margin: 0 auto;
}
.li-lp-section-surf {
  background: var(--lp-surface);
}
.li-lp-eyebrow {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--lp-accent);
  margin-bottom: 10px;
}
.li-lp-h2 {
  font-size: clamp(22px, 3vw, 36px);
  font-weight: 900;
  color: var(--lp-text);
  margin: 0 0 8px;
  letter-spacing: -0.02em;
  line-height: 1.15;
}
.li-lp-body {
  font-size: 15px;
  line-height: 1.85;
  color: #4b5563;
  max-width: 740px;
}

/* Galeria Mosaic */
.li-lp-gallery {
  margin-top: 32px;
}
.li-lp-gallery-main {
  display: grid;
  grid-template-columns: 2fr 1fr;
  grid-template-rows: 280px 220px;
  gap: 4px;
  border-radius: 16px;
  overflow: hidden;
}
.li-lp-gallery-main .li-lp-gitem:first-child {
  grid-row: 1/3;
}
.li-lp-gitem {
  overflow: hidden;
  cursor: pointer;
  position: relative;
  background: #e2e8f0;
}
.li-lp-gitem img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.4s ease;
}
.li-lp-gitem:hover img {
  transform: scale(1.05);
}
.li-lp-gitem-more {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 20px;
  font-weight: 800;
}
.li-lp-gallery-strip {
  display: flex;
  gap: 6px;
  margin-top: 6px;
  overflow-x: auto;
  scrollbar-width: none;
}
.li-lp-gallery-strip::-webkit-scrollbar {
  display: none;
}
.li-lp-gallery-strip .li-lp-gitem {
  width: 100px;
  min-width: 100px;
  height: 72px;
  border-radius: 8px;
  flex-shrink: 0;
}

/* Amenidades */
.li-lp-amen-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 10px;
  margin-top: 28px;
}
.li-lp-amen-item {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #fff;
  border: 1px solid var(--lp-border);
  border-radius: 12px;
  padding: 13px 14px;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.li-lp-amen-item:hover {
  border-color: var(--lp-accent);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.07);
}
.li-lp-amen-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--lp-accent);
  flex-shrink: 0;
}

/* Stats Trio */
.li-lp-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 1px;
  background: var(--lp-border);
  border: 1px solid var(--lp-border);
  border-radius: 16px;
  overflow: hidden;
  margin-top: 32px;
}
.li-lp-stat {
  background: #fff;
  padding: 28px 24px;
}
.li-lp-stat-val {
  font-size: 32px;
  font-weight: 900;
  color: var(--lp-text);
  letter-spacing: -0.03em;
  line-height: 1;
}
.li-lp-stat-lbl {
  font-size: 11px;
  font-weight: 700;
  color: var(--lp-muted);
  letter-spacing: 0.07em;
  text-transform: uppercase;
  margin-top: 6px;
}

/* Tags de Localização */
.li-lp-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
}
.li-lp-tag {
  background: #fff;
  border: 1px solid var(--lp-border);
  color: #374151;
  font-size: 12px;
  font-weight: 600;
  padding: 8px 16px;
  border-radius: 999px;
}

/* Plantas */
.li-lp-plants-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 14px;
  margin-top: 24px;
}
.li-lp-plant-item {
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid var(--lp-border);
  cursor: pointer;
  background: var(--lp-surface);
  transition: transform 0.2s, box-shadow 0.2s;
}
.li-lp-plant-item:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}
.li-lp-plant-item img {
  width: 100%;
  max-height: 180px;
  object-fit: contain;
  display: block;
}

/* CTA Contact Box for Non-Brokers */
.li-lp-contact-box {
  background: linear-gradient(135deg, var(--lp-dark) 0%, #1e2a4a 100%);
  border-radius: 24px;
  overflow: hidden;
  display: grid;
  grid-template-columns: 1fr 1fr;
}
@media (max-width: 700px) {
  .li-lp-contact-box {
    grid-template-columns: 1fr;
  }
}
.li-lp-contact-left {
  padding: 48px 40px;
}
.li-lp-contact-right {
  background: rgba(255, 255, 255, 0.04);
  border-left: 1px solid rgba(255, 255, 255, 0.08);
  padding: 48px 40px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.li-lp-contact-h {
  font-size: clamp(22px, 3vw, 32px);
  font-weight: 900;
  color: #fff;
  margin: 0 0 10px;
  letter-spacing: -0.02em;
}
.li-lp-contact-p {
  font-size: 14px;
  line-height: 1.7;
  color: rgba(255, 255, 255, 0.6);
  margin: 0 0 24px;
}
.li-lp-contact-feature {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.li-lp-contact-feature-icon {
  font-size: 20px;
  margin-top: 1px;
}
.li-lp-contact-feature-text {
  font-size: 13px;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.75);
  line-height: 1.5;
}

/* Ficha Técnica */
.li-lp-ficha-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 20px;
  font-size: 14px;
}
.li-lp-ficha-table tr {
  border-bottom: 1px solid var(--lp-border);
}
.li-lp-ficha-table td {
  padding: 13px 0;
  vertical-align: top;
}
.li-lp-ficha-table td:first-child {
  width: 42%;
  color: var(--lp-muted);
  font-weight: 600;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.li-lp-ficha-table td:last-child {
  color: var(--lp-text);
  font-weight: 600;
}

/* CTA Final */
.li-lp-cta-final {
  background: var(--lp-accent);
  padding: clamp(48px, 7vw, 96px) clamp(20px, 5vw, 64px);
  text-align: center;
  position: relative;
  overflow: hidden;
}
.li-lp-cta-final::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 30% 50%, rgba(255, 255, 255, 0.08) 0%, transparent 60%),
              radial-gradient(ellipse at 80% 20%, rgba(0, 0, 0, 0.12) 0%, transparent 50%);
  pointer-events: none;
}
.li-lp-cta-h {
  font-size: clamp(26px, 4vw, 44px);
  font-weight: 900;
  color: #fff;
  margin: 0 0 12px;
  position: relative;
  letter-spacing: -0.03em;
}
.li-lp-cta-sub {
  font-size: 15px;
  color: rgba(255, 255, 255, 0.78);
  margin: 0 0 36px;
  position: relative;
}
.li-lp-cta-form {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  max-width: 580px;
  margin: 0 auto;
  position: relative;
}
.li-lp-cta-field {
  flex: 1;
  min-width: 140px;
  background: rgba(255, 255, 255, 0.15);
  border: 1.5px solid rgba(255, 255, 255, 0.3);
  color: #fff;
  font-size: 14px;
  font-weight: 500;
  font-family: 'Montserrat', sans-serif;
  padding: 14px 18px;
  border-radius: 12px;
  outline: none;
}
.li-lp-cta-field::placeholder {
  color: rgba(255, 255, 255, 0.55);
}
.li-lp-cta-field:focus {
  border-color: rgba(255, 255, 255, 0.8);
}
.li-lp-cta-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #fff;
  color: var(--lp-accent);
  border: none;
  cursor: pointer;
  font-family: 'Montserrat', sans-serif;
  font-size: 14px;
  font-weight: 800;
  padding: 14px 30px;
  border-radius: 12px;
  white-space: nowrap;
  width: 100%;
  justify-content: center;
  margin-top: 4px;
  transition: opacity 0.15s, transform 0.15s;
}
.li-lp-cta-btn:hover {
  opacity: 0.9;
  transform: scale(1.02);
}

/* Footer */
.li-lp-footer {
  background: var(--lp-dark);
  padding: 36px clamp(20px, 5vw, 64px);
  text-align: center;
}
.li-lp-footer-logo {
  height: 30px;
  object-fit: contain;
  filter: brightness(0) invert(1);
  opacity: 0.6;
  margin-bottom: 12px;
}
.li-lp-footer-name {
  font-size: 14px;
  font-weight: 800;
  color: #fff;
  margin-bottom: 4px;
}
.li-lp-footer-info {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.45);
  margin-top: 2px;
}

/* Video */
.li-lp-video-wrap {
  position: relative;
  padding-bottom: 56.25%;
  height: 0;
  overflow: hidden;
  border-radius: 16px;
  margin-top: 28px;
}
.li-lp-video-wrap iframe {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

/* Map */
.li-lp-map-wrap {
  border-radius: 16px;
  overflow: hidden;
  height: 320px;
  margin-top: 24px;
}
.li-lp-map-wrap iframe {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}

/* Responsive */
@media (max-width: 760px) {
  .li-lp-gallery-main {
    grid-template-columns: 1fr;
    grid-template-rows: 240px 160px 160px;
  }
  .li-lp-gallery-main .li-lp-gitem:first-child {
    grid-row: auto;
  }
  .li-lp-nav {
    padding: 16px 20px;
  }
  .li-lp-hero-body {
    padding: 24px 20px 48px;
  }
  .li-lp-ctabar {
    padding: 20px;
  }
  .li-lp-ctabar-inner {
    flex-direction: column;
    gap: 14px;
  }
  .li-lp-ctabar-text {
    width: 100%;
  }
  .li-lp-ctabar-fields {
    width: 100%;
  }
  .li-lp-contact-left, .li-lp-contact-right {
    padding: 32px 24px;
  }
  .li-lp-cta-final {
    padding: 48px 20px;
  }
}
@media (max-width: 480px) {
  .li-lp-ctabar-fields {
    flex-direction: column;
  }
  .li-lp-field, .li-lp-btn-white {
    width: 100%;
  }
  .li-lp-stats {
    grid-template-columns: 1fr 1fr;
  }
}
</style>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">

<div class="li-lp" id="li-lp-<?php echo $pid;?>">

<?php /* ═══════════ HERO ═══════════ */ ?>
<div class="li-lp-hero">
  <?php if($c_cover):?><div class="li-lp-hero-bg" style="background-image:url('<?php echo esc_url($c_cover);?>');"></div><?php endif;?>
  <div class="li-lp-hero-grad"></div>

  <nav class="li-lp-nav">
    <?php if($c_logo):?><img src="<?php echo esc_url($c_logo);?>" alt="<?php echo esc_attr($co_name);?>" class="li-lp-nav-logo"><?php else:?><span class="li-lp-nav-brand"><?php echo esc_html($co_name);?></span><?php endif;?>
    <?php if($c_whats):?><a href="https://wa.me/<?php echo esc_attr(preg_replace('/\D/','',$c_whats));?>" target="_blank" class="li-lp-nav-cta">💬 Falar com corretor</a><?php endif;?>
  </nav>

  <div class="li-lp-hero-body">
    <?php if($c_badge):?><div class="li-lp-badge-pill">✦ <?php echo esc_html($c_badge);?></div><?php endif;?>
    <h1 class="li-lp-h1"><?php echo esc_html($c_title);?></h1>
    <?php if($c_addr):?><div class="li-lp-loc">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      <?php echo esc_html($c_addr);?>
    </div><?php endif;?>
    <div class="li-lp-pills">
      <?php if($beds_str):?><span class="li-lp-pill">🛏 <?php echo esc_html($beds_str);?></span><?php endif;?>
      <?php if($area_str):?><span class="li-lp-pill">📐 <?php echo esc_html($area_str);?></span><?php endif;?>
      <?php if($price_min):?><span class="li-lp-pill">A partir de <?php echo ledimov_money($price_min);?></span><?php endif;?>
      <?php if($avail>0):?><span class="li-lp-pill li-lp-pill-green">🟢 <?php echo $avail;?> disponíveis</span><?php endif;?>
      <?php if($c_delivery):?><span class="li-lp-pill">📅 Entrega: <?php echo esc_html($c_delivery);?></span><?php endif;?>
    </div>
  </div>
</div>

<?php /* ═══════════ CTA BAR ═══════════ */ ?>
<?php if($c_whats):?>
<div class="li-lp-ctabar">
  <div class="li-lp-ctabar-inner">
    <div class="li-lp-ctabar-text">
      <div class="li-lp-ctabar-title">Interesse neste empreendimento?</div>
      <div class="li-lp-ctabar-sub">Fale agora com um especialista.</div>
    </div>
    <div class="li-lp-ctabar-fields">
      <input id="lp-nome-<?php echo $pid;?>" class="li-lp-field" type="text" placeholder="Seu nome" autocomplete="name">
      <input id="lp-fone-<?php echo $pid;?>" class="li-lp-field" type="tel" placeholder="Seu WhatsApp">
      <button class="li-lp-btn-white" onclick="liLpSubmit(<?php echo $pid;?>,event)">💬 Conversar</button>
    </div>
  </div>
</div>
<?php endif;?>

<?php /* ═══════════ DESCRIÇÃO + STATS ═══════════ */ ?>
<?php if($c_desc||$beds_str||$area_str||$avail):?>
<div class="li-lp-section">
<div class="li-lp-section-inner">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px,5vw,80px);align-items:start;">
    <?php if($c_desc):?>
    <div>
      <div class="li-lp-eyebrow">Sobre o empreendimento</div>
      <h2 class="li-lp-h2">Um projeto feito para você</h2>
      <p class="li-lp-body"><?php echo nl2br(esc_html($c_desc));?></p>
    </div>
    <?php else:?><div></div><?php endif;?>
    <div>
      <div class="li-lp-stats">
        <?php if($beds_str):?><div class="li-lp-stat"><div class="li-lp-stat-val"><?php echo esc_html($beds_str);?></div><div class="li-lp-stat-lbl">Dormitórios</div></div><?php endif;?>
        <?php if($area_str):?><div class="li-lp-stat"><div class="li-lp-stat-val"><?php echo esc_html($area_str);?></div><div class="li-lp-stat-lbl">Área útil</div></div><?php endif;?>
        <?php if($avail):?><div class="li-lp-stat"><div class="li-lp-stat-val" style="color:var(--lp-accent)"><?php echo $avail;?></div><div class="li-lp-stat-lbl">Unid. disponíveis</div></div><?php endif;?>
        <?php if($c_delivery):?><div class="li-lp-stat"><div class="li-lp-stat-val" style="font-size:18px;"><?php echo esc_html($c_delivery);?></div><div class="li-lp-stat-lbl">Entrega prevista</div></div><?php endif;?>
      </div>
    </div>
  </div>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ GALERIA ═══════════ */ ?>
<?php if(!empty($gallery)):?>
<div class="li-lp-section" style="padding-top:0;">
<div class="li-lp-section-inner">
  <div class="li-lp-eyebrow">Galeria</div>
  <h2 class="li-lp-h2">Conheça de perto</h2>
  <div class="li-lp-gallery">
    <?php if(count($gallery)>=3):?>
    <div class="li-lp-gallery-main">
      <?php foreach(array_slice($gallery,0,3) as $gi_i=>$gi):$last=($gi_i===2&&count($gallery)>3);?>
      <div class="li-lp-gitem" onclick="liLightbox.open(<?php echo json_encode($gallery_urls);?>,<?php echo $gi_i;?>)">
        <img src="<?php echo esc_url($gi->url);?>" alt="">
        <?php if($last):?><div class="li-lp-gitem-more">+<?php echo count($gallery)-3;?> fotos</div><?php endif;?>
      </div>
      <?php endforeach;?>
    </div>
    <?php if(count($gallery)>3):?>
    <div class="li-lp-gallery-strip">
      <?php foreach(array_slice($gallery,3) as $si=>$gi):?>
      <div class="li-lp-gitem" onclick="liLightbox.open(<?php echo json_encode($gallery_urls);?>,<?php echo $si+3;?>)">
        <img src="<?php echo esc_url($gi->thumb_url?:$gi->url);?>" alt="">
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>
    <?php elseif(count($gallery)===1):?>
    <div style="border-radius:16px;overflow:hidden;height:420px;">
      <img src="<?php echo esc_url($gallery[0]->url);?>" style="width:100%;height:100%;object-fit:cover;" alt="">
    </div>
    <?php elseif(count($gallery)===2):?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;border-radius:16px;overflow:hidden;height:360px;">
      <?php foreach($gallery as $gi_i=>$gi):?><div class="li-lp-gitem" onclick="liLightbox.open(<?php echo json_encode($gallery_urls);?>,<?php echo $gi_i;?>)"><img src="<?php echo esc_url($gi->url);?>" alt=""></div><?php endforeach;?>
    </div>
    <?php endif;?>
  </div>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ AMENIDADES ═══════════ */ ?>
<?php if(!empty($amen_arr)):?>
<div class="li-lp-section li-lp-section-surf">
<div class="li-lp-section-inner">
  <div class="li-lp-eyebrow">Lazer & Infraestrutura</div>
  <h2 class="li-lp-h2">Tudo que você precisa aqui dentro</h2>
  <div class="li-lp-amen-grid">
    <?php foreach($amen_arr as $am):?><div class="li-lp-amen-item"><span class="li-lp-amen-dot"></span><?php echo esc_html($am);?></div><?php endforeach;?>
  </div>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ VÍDEO ═══════════ */ ?>
<?php if($c_video):?>
<div class="li-lp-section">
<div class="li-lp-section-inner">
  <div class="li-lp-eyebrow">Vídeo</div>
  <h2 class="li-lp-h2">Veja ao vivo</h2>
  <div class="li-lp-video-wrap"><iframe src="<?php echo esc_url($c_video);?>" allowfullscreen loading="lazy"></iframe></div>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ PLANTAS ═══════════ */ ?>
<?php if(!empty($plants_gallery)):?>
<div class="li-lp-section li-lp-section-surf">
<div class="li-lp-section-inner">
  <div class="li-lp-eyebrow">Plantas</div>
  <h2 class="li-lp-h2"><?php echo $c_plant_text?esc_html($c_plant_text):'Conheça as plantas';?></h2>
  <div class="li-lp-plants-grid">
    <?php foreach($plants_gallery as $pg_i=>$pg):?><div class="li-lp-plant-item" onclick="liLightbox.open(<?php echo json_encode($plant_urls);?>,<?php echo $pg_i;?>)"><img src="<?php echo esc_url($pg->url);?>" alt="Planta"></div><?php endforeach;?>
  </div>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ CAIXA DE CONTATO ═══════════ */ ?>
<?php if($c_whats):?>
<div class="li-lp-section">
<div class="li-lp-section-inner">
  <div class="li-lp-contact-box">
    <div class="li-lp-contact-left">
      <div class="li-lp-eyebrow" style="color:rgba(255,255,255,.5);">Fale conosco</div>
      <h2 class="li-lp-contact-h">Pronto para dar<br>o próximo passo?</h2>
      <p class="li-lp-contact-p">Nossa equipe está pronta para responder todas as suas dúvidas e ajudá-lo a encontrar o apartamento ideal.</p>
      <div style="display:flex;flex-direction:column;gap:14px;">
        <div class="li-lp-contact-feature"><span class="li-lp-contact-feature-icon">⚡</span><span class="li-lp-contact-feature-text">Resposta rápida pelo WhatsApp</span></div>
        <div class="li-lp-contact-feature"><span class="li-lp-contact-feature-icon">🔒</span><span class="li-lp-contact-feature-text">Seus dados estão protegidos</span></div>
        <div class="li-lp-contact-feature"><span class="li-lp-contact-feature-icon">🏆</span><span class="li-lp-contact-feature-text">Atendimento especializado</span></div>
      </div>
    </div>
    <div class="li-lp-contact-right">
      <div style="font-size:15px;font-weight:800;color:#fff;margin-bottom:4px;">Receber mais informações</div>
      <div style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:8px;">Preencha e entraremos em contato.</div>
      <input id="lp-nome-cxbox-<?php echo $pid;?>" class="li-lp-cta-field" type="text" placeholder="Seu nome completo" autocomplete="name" style="border-radius:10px;">
      <input id="lp-fone-cxbox-<?php echo $pid;?>" class="li-lp-cta-field" type="tel" placeholder="WhatsApp com DDD" style="border-radius:10px;">
      <button class="li-lp-btn-white" style="border-radius:10px;" onclick="liLpSubmitBox(<?php echo $pid;?>,event)">💬 Falar pelo WhatsApp →</button>
    </div>
  </div>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ LOCALIZAÇÃO ═══════════ */ ?>
<?php if($c_loc_text||$c_maps||!empty($tags_arr)):?>
<div class="li-lp-section li-lp-section-surf">
<div class="li-lp-section-inner">
  <div class="li-lp-eyebrow">Localização</div>
  <h2 class="li-lp-h2">Endereço privilegiado</h2>
  <?php if($c_loc_text):?><p class="li-lp-body" style="margin-top:12px;"><?php echo nl2br(esc_html($c_loc_text));?></p><?php endif;?>
  <?php if(!empty($tags_arr)):?><div class="li-lp-tags" style="margin-top:16px;"><?php foreach($tags_arr as $tag):?><span class="li-lp-tag">📍 <?php echo esc_html($tag);?></span><?php endforeach;?></div><?php endif;?>
  <?php if($c_maps):?><div class="li-lp-map-wrap"><iframe src="<?php echo esc_url($c_maps);?>" allowfullscreen loading="lazy"></iframe></div><?php endif;?>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ FICHA TÉCNICA ═══════════ */ ?>
<?php if($c_ficha||$c_legal||$c_delivery):?>
<div class="li-lp-section">
<div class="li-lp-section-inner" style="max-width:700px;">
  <div class="li-lp-eyebrow">Ficha Técnica</div>
  <?php if($c_ficha):?><p class="li-lp-body" style="margin-top:10px;"><?php echo nl2br(esc_html($c_ficha));?></p><?php endif;?>
  <table class="li-lp-ficha-table">
    <?php if($c_title):?><tr><td>Empreendimento</td><td><?php echo esc_html($c_title);?></td></tr><?php endif;?>
    <?php if($c_addr):?><tr><td>Endereço</td><td><?php echo esc_html($c_addr);?></td></tr><?php endif;?>
    <?php if($beds_str):?><tr><td>Tipologia</td><td><?php echo esc_html($beds_str);?></td></tr><?php endif;?>
    <?php if($area_str):?><tr><td>Área Útil</td><td><?php echo esc_html($area_str);?></td></tr><?php endif;?>
    <?php if($c_delivery):?><tr><td>Previsão de Entrega</td><td><?php echo esc_html($c_delivery);?></td></tr><?php endif;?>
    <?php if($c_legal):?><tr><td>Registro</td><td><?php echo esc_html($c_legal);?></td></tr><?php endif;?>
    <?php if($co_name):?><tr><td>Incorporadora</td><td><?php echo esc_html($co_name);?></td></tr><?php endif;?>
  </table>
</div>
</div>
<?php endif;?>

<?php /* ═══════════ CTA FINAL ═══════════ */ ?>
<?php if($c_whats&&!$is_logged):?>
<div class="li-lp-cta-final">
  <h2 class="li-lp-cta-h">Agende uma visita</h2>
  <p class="li-lp-cta-sub">Conheça pessoalmente. Nossa equipe te espera.</p>
  <div class="li-lp-cta-form">
    <input id="lp-nome-cta-<?php echo $pid;?>" class="li-lp-cta-field" type="text" placeholder="Seu nome" autocomplete="name">
    <input id="lp-fone-cta-<?php echo $pid;?>" class="li-lp-cta-field" type="tel" placeholder="Seu WhatsApp">
    <button class="li-lp-cta-btn" onclick="liLpSubmitCta(<?php echo $pid;?>,event)">💬 Falar pelo WhatsApp</button>
  </div>
</div>
<?php endif;?>


</div><!-- .li-lp -->

<script>
window.liLpSubmit=function(pid,e){
  if(e)e.preventDefault();
  var nome=(document.getElementById('lp-nome-'+pid)||{value:''}).value.trim();
  var fone=(document.getElementById('lp-fone-'+pid)||{value:''}).value.trim();
  if(!nome||!fone)return liToast('Preencha nome e WhatsApp','warning');
  var whats='<?php echo esc_js(preg_replace('/\D/','',$c_whats));?>';
  var prop='<?php echo esc_js($c_title);?>';
  var msg='Olá! Me chamo '+nome+'. Tenho interesse no '+prop+'. Meu WhatsApp: '+fone;
  window.open('https://wa.me/'+whats+'?text='+encodeURIComponent(msg),'_blank');
};
window.liLpSubmitBox=function(pid,e){
  if(e)e.preventDefault();
  var nome=(document.getElementById('lp-nome-cxbox-'+pid)||{value:''}).value.trim();
  var fone=(document.getElementById('lp-fone-cxbox-'+pid)||{value:''}).value.trim();
  if(!nome||!fone)return liToast('Preencha nome e WhatsApp','warning');
  var whats='<?php echo esc_js(preg_replace('/\D/','',$c_whats));?>';
  var prop='<?php echo esc_js($c_title);?>';
  var msg='Olá! Sou '+nome+' e gostaria de saber mais sobre o '+prop+'. Meu contato: '+fone;
  window.open('https://wa.me/'+whats+'?text='+encodeURIComponent(msg),'_blank');
};
window.liLpSubmitCta=function(pid,e){
  if(e)e.preventDefault();
  var nome=(document.getElementById('lp-nome-cta-'+pid)||{value:''}).value.trim();
  var fone=(document.getElementById('lp-fone-cta-'+pid)||{value:''}).value.trim();
  if(!nome||!fone)return liToast('Preencha nome e WhatsApp','warning');
  var whats='<?php echo esc_js(preg_replace('/\D/','',$c_whats));?>';
  var prop='<?php echo esc_js($c_title);?>';
  var msg='Olá! Sou '+nome+' e gostaria de agendar uma visita ao '+prop+'. Meu contato: '+fone;
  window.open('https://wa.me/'+whats+'?text='+encodeURIComponent(msg),'_blank');
};
</script>
<?php
    return ob_get_clean();
}

/* ============================================================
   15. ADMIN – EDITOR DE TEMPLATES
   ============================================================ */
function ledimov_admin_templates(){
    $shortcodes=[
        'card_imovel'=>['label'=>'Landing Page do Empreendimento','sc'=>'[ledimov_card_imovel id="X"]','desc'=>'Página completa de um empreendimento: galeria, detalhes, plantas, localização e contato.'],
        'area_corretor'=>['label'=>'Área do Corretor','sc'=>'[ledimov_area_corretor]','desc'=>'Portal de login, cadastro e painel do corretor com tabelas de unidades.'],
        'vitrine'=>['label'=>'Vitrine de Empreendimentos','sc'=>'[ledimov_vitrine]','desc'=>'Grade com todos os empreendimentos ativos e seus preços mínimos.'],
        'destaque'=>['label'=>'Empreendimentos em Destaque','sc'=>'[ledimov_destaque]','desc'=>'Seção hero com os empreendimentos marcados como destaque.'],
        'tabela'=>['label'=>'Tabela de Unidades','sc'=>'[ledimov_tabela id="X"]','desc'=>'Tabela interativa de unidades com filtros por andar, quartos e status.'],
        'mapa'=>['label'=>'Mapa do Prédio','sc'=>'[ledimov_mapa id="X"]','desc'=>'Planta interativa do prédio com coloração por status de cada unidade.'],
    ];
    $active=sanitize_key($_GET['tpl']??array_key_first($shortcodes));
    if(!isset($shortcodes[$active]))$active=array_key_first($shortcodes);
    $current_code=get_option('ledimov_tpl_'.$active,'');
    $has_custom=(bool)$current_code;
    ?>
<div class="wrap ledimov-wrap">
<h1 style="font-size:24px;font-weight:800;margin-bottom:4px;">🎨 Editor de Templates</h1>
<p style="color:var(--li-muted);margin-bottom:24px;font-size:13px;">Edite o código PHP/HTML de cada shortcode. As alterações propagam imediatamente para todos os usuários. Deixe em branco para usar o template padrão do plugin.</p>

<div style="display:flex;gap:20px;align-items:flex-start;">

<!-- Sidebar: lista de shortcodes -->
<div style="min-width:220px;max-width:240px;">
<?php foreach($shortcodes as $key=>$info):
    $is_active=($key===$active);
    $is_custom=(bool)get_option('ledimov_tpl_'.$key,'');
    ?>
<a href="?page=ledimov-templates&tpl=<?php echo $key;?>"
   style="display:block;padding:11px 14px;border-radius:10px;text-decoration:none;margin-bottom:6px;border:1px solid <?php echo $is_active?'var(--li-accent)':'var(--li-border)';?>;background:<?php echo $is_active?'var(--li-accent-l)':'var(--li-card)';?>;color:<?php echo $is_active?'var(--li-accent)':'var(--li-text)';?>;font-size:13px;font-weight:<?php echo $is_active?700:500;?>;">
  <div><?php echo esc_html($info['label']);?></div>
  <div style="font-family:monospace;font-size:10px;color:var(--li-muted);margin-top:2px;"><?php echo esc_html($info['sc']);?></div>
  <?php if($is_custom):?><div style="font-size:10px;color:#1a7a4a;margin-top:4px;font-weight:600;">● Personalizado</div><?php else:?><div style="font-size:10px;color:var(--li-muted);margin-top:4px;">○ Padrão do plugin</div><?php endif;?>
</a>
<?php endforeach;?>
</div>

<!-- Editor principal -->
<div style="flex:1;min-width:0;">
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:14px;padding:22px;">

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
<div>
  <div style="font-size:17px;font-weight:700;"><?php echo esc_html($shortcodes[$active]['label']);?></div>
  <div style="font-size:12px;color:var(--li-muted);margin-top:3px;"><?php echo esc_html($shortcodes[$active]['desc']);?></div>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap;">
  <button id="li-tpl-load-default" class="li-btn li-btn-outline" style="font-size:12px;">📥 Carregar código padrão</button>
  <button id="li-tpl-reset" class="li-btn" style="font-size:12px;background:#fff3cd;border:1px solid #ffc107;color:#856404;" <?php echo !$has_custom?'disabled':'';?>>🔄 Restaurar padrão</button>
  <button id="li-tpl-save" class="li-btn li-btn-primary" style="font-size:12px;">💾 Salvar template</button>
</div>
</div>

<div id="li-tpl-status" style="margin-bottom:10px;font-size:12px;">
<?php if($has_custom):?>
<span style="background:#d4efdf;color:#1a7a4a;border-radius:6px;padding:4px 10px;font-weight:600;">✅ Template personalizado ativo</span>
<?php else:?>
<span style="background:var(--li-card-2);color:var(--li-muted);border-radius:6px;padding:4px 10px;">Usando template padrão do plugin</span>
<?php endif;?>
</div>

<div id="li-tpl-loading" style="display:none;padding:12px;font-size:13px;color:var(--li-muted);">⏳ Carregando código padrão…</div>
<div id="li-tpl-msg" style="display:none;margin-bottom:10px;border-radius:8px;padding:10px 14px;font-size:13px;"></div>

<div style="border:1px solid var(--li-border);border-radius:8px;overflow:hidden;">
<textarea id="li-tpl-editor" style="width:100%;min-height:480px;font-family:monospace;font-size:13px;line-height:1.6;padding:14px;box-sizing:border-box;border:none;background:#1e1e2e;color:#cdd6f4;resize:vertical;"><?php echo esc_textarea($current_code);?></textarea>
</div>

<div style="margin-top:10px;font-size:11px;color:var(--li-muted);">
💡 <strong>Variáveis disponíveis:</strong> todas as variáveis locais da função original estão disponíveis no template (ex: <code>$pid</code>, <code>$broker</code>, <code>$prop</code>, <code>$units</code>, <code>$wpdb</code> etc.). Use PHP normal com tags <code>&lt;?php ?&gt;</code>.
</div>
</div>
</div><!-- /editor -->
</div><!-- /flex -->
</div><!-- /wrap -->

<script>
(function($){
  var tplKey='<?php echo esc_js($active);?>';
  var nonce=window.ledimovNonce||'';
  var editor=null;

  // Init CodeMirror if available
  $(function(){
    var ta=document.getElementById('li-tpl-editor');
    if(window.wp&&wp.codeEditor){
      var cmSettings=wp.codeEditor.defaultSettings?$.extend(true,{},wp.codeEditor.defaultSettings):{};
      if(cmSettings.codemirror){
        cmSettings.codemirror=$.extend({},cmSettings.codemirror,{
          indentUnit:2,tabSize:2,lineNumbers:true,lineWrapping:true,
          mode:'application/x-httpd-php',theme:'default'
        });
      }
      var inst=wp.codeEditor.initialize(ta,cmSettings);
      editor=inst.codemirror||null;
    }
  });

  function getCode(){
    if(editor)return editor.getValue();
    return document.getElementById('li-tpl-editor').value;
  }
  function setCode(code){
    if(editor){editor.setValue(code);editor.refresh();}
    else document.getElementById('li-tpl-editor').value=code;
  }
  function showMsg(msg,ok){
    var el=$('#li-tpl-msg');
    el.css({display:'block',background:ok?'#d4efdf':'#fdecea',color:ok?'#1a7a4a':'#7b241c',border:'1px solid '+(ok?'#6ee7b7':'#f5b7b1')});
    el.text(msg);
    setTimeout(function(){el.fadeOut(400,function(){el.hide();});},3500);
  }
  function updateStatus(hasCustom){
    var s=$('#li-tpl-status');
    if(hasCustom){
      s.html('<span style="background:#d4efdf;color:#1a7a4a;border-radius:6px;padding:4px 10px;font-weight:600;">✅ Template personalizado ativo</span>');
      $('#li-tpl-reset').prop('disabled',false);
    }else{
      s.html('<span style="background:var(--li-card-2);color:var(--li-muted);border-radius:6px;padding:4px 10px;">Usando template padrão do plugin</span>');
      $('#li-tpl-reset').prop('disabled',true);
    }
  }

  $('#li-tpl-load-default').on('click',function(){
    $('#li-tpl-loading').show();
    $.post(ajaxurl,{action:'ledimov_get_default_tpl',nonce:nonce,key:tplKey},function(res){
      $('#li-tpl-loading').hide();
      if(res.success){
        // Strip function wrapper, keep only the body content
        var code=res.data.code;
        setCode(code);
        showMsg('Código padrão carregado. Edite e salve para personalizar.', true);
      }else{
        showMsg('Erro: '+(res.data||'Falha ao carregar código')+'',false);
      }
    },'json').fail(function(){$('#li-tpl-loading').hide();showMsg('Erro de comunicação.',false);});
  });

  $('#li-tpl-save').on('click',function(){
    var code=getCode();
    $.post(ajaxurl,{action:'ledimov_save_tpl',nonce:nonce,key:tplKey,code:code},function(res){
      if(res.success){showMsg('✅ Template salvo com sucesso!',true);updateStatus(code.trim()!=='');}
      else showMsg('❌ '+(res.data||'Erro ao salvar'),false);
    },'json').fail(function(){showMsg('Erro de comunicação.',false);});
  });

  $('#li-tpl-reset').on('click',function(){
    if(!confirm('Restaurar o template padrão do plugin? O código personalizado será removido.'))return;
    $.post(ajaxurl,{action:'ledimov_reset_tpl',nonce:nonce,key:tplKey},function(res){
      if(res.success){setCode('');showMsg('✅ Template restaurado ao padrão!',true);updateStatus(false);}
      else showMsg('❌ '+(res.data||'Erro'),false);
    },'json').fail(function(){showMsg('Erro de comunicação.',false);});
  });

})(jQuery);
</script>
<?php }

/* ============================================================
   16. ADMIN – CRIAR PÁGINAS
   ============================================================ */
function ledimov_admin_setup(){global $wpdb;$pt=$wpdb->prefix.'ledimov_properties';$props=$wpdb->get_results("SELECT id,title FROM {$pt} ORDER BY title");?>
<div class="wrap ledimov-wrap"><h1 style="font-size:24px;font-weight:800;margin-bottom:6px;">🔧 Criar Páginas WordPress</h1>
<p style="color:var(--li-muted);margin-bottom:28px;">Crie automaticamente as páginas com os shortcodes do LedImov.</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;">

<?php $pages=[
    ['slug'=>'vitrine-imoveis','title'=>'Vitrine de Imóveis','shortcode'=>'[ledimov_vitrine]','desc'=>'Grade com todos os empreendimentos ativos'],
    ['slug'=>'area-corretor','title'=>'Área do Corretor','shortcode'=>'[ledimov_area_corretor]','desc'=>'Login, cadastro e portal do corretor'],
];
foreach($pages as $pg):$exists=get_page_by_path($pg['slug']);$url=$exists?get_permalink($exists->ID):'';?>
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:12px;padding:22px;">
<div style="font-size:16px;font-weight:700;margin-bottom:4px;"><?php echo esc_html($pg['title']);?></div>
<div style="font-size:12px;color:var(--li-muted);margin-bottom:10px;"><?php echo esc_html($pg['desc']);?></div>
<code style="display:block;background:var(--li-card-2);border:1px solid var(--li-border);border-radius:6px;padding:8px 10px;font-size:11px;margin-bottom:12px;"><?php echo esc_html($pg['shortcode']);?></code>
<?php if($exists):?><div style="display:flex;gap:8px;"><a href="<?php echo esc_url($url);?>" target="_blank" class="li-btn li-btn-outline" style="text-decoration:none;font-size:12px;">🔗 Ver Página</a><a href="<?php echo get_edit_post_link($exists->ID);?>" class="li-btn li-btn-outline" style="text-decoration:none;font-size:12px;">✏️ Editar</a></div>
<?php else:?><button class="li-btn li-btn-primary" style="font-size:12px;" onclick="liCreatePage('<?php echo esc_js($pg['slug']);?>','<?php echo esc_js($pg['title']);?>','<?php echo esc_js($pg['shortcode']);?>')">+ Criar Página</button><?php endif;?>
</div>
<?php endforeach;?>

<?php foreach($props as $p):$p_id=intval($p->id);$p_title=isset($p->title)?$p->title:'';$slug=sanitize_title($p_title);$exists=get_page_by_path($slug);$url=$exists?get_permalink($exists->ID):'';$sc='[ledimov_card_imovel id="'.$p_id.'"]';?>
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:12px;padding:22px;">
<div style="font-size:16px;font-weight:700;margin-bottom:4px;"><?php echo esc_html($p_title);?></div>
<div style="font-size:12px;color:var(--li-muted);margin-bottom:10px;">Landing page do empreendimento</div>
<code style="display:block;background:var(--li-card-2);border:1px solid var(--li-border);border-radius:6px;padding:8px 10px;font-size:11px;margin-bottom:12px;"><?php echo esc_html($sc);?></code>
<?php if($exists):?><div style="display:flex;gap:8px;"><a href="<?php echo esc_url($url);?>" target="_blank" class="li-btn li-btn-outline" style="text-decoration:none;font-size:12px;">🔗 Ver Página</a><a href="<?php echo get_edit_post_link($exists->ID);?>" class="li-btn li-btn-outline" style="text-decoration:none;font-size:12px;">✏️ Editar</a></div>
<?php else:?><button class="li-btn li-btn-primary" style="font-size:12px;" onclick="liCreatePage('<?php echo esc_js($slug);?>','<?php echo esc_js($p_title);?>','<?php echo esc_js($sc);?>')">+ Criar Página</button><?php endif;?>
</div>
<?php endforeach;?>
</div>
<div id="li-create-notice" style="display:none;margin-top:18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:14px 18px;font-size:13px;color:#065f46;"></div>
<script>
function liCreatePage(slug,title,sc){liAjax('ledimov_create_page',{slug:slug,title:title,shortcode:sc},function(err,res){var box=document.getElementById('li-create-notice');box.style.display='block';if(err||!res.success){box.style.background='#fdecea';box.style.borderColor='#f5b7b1';box.style.color='#7b241c';box.innerHTML='❌ '+(res?res.data:'Erro');return;}var d=res.data;box.innerHTML='✅ Página "'+(d.existed?'já existia':'criada')+'": <a href="'+d.view_url+'" target="_blank" style="color:#065f46">Ver →</a> | <a href="'+d.edit_url+'" target="_blank" style="color:#065f46">Editar →</a>';setTimeout(function(){location.reload();},2000);});}
</script>
</div>
<?php }

/* ============================================================
   16. ADMIN – EDITAR PÁGINAS
   ============================================================ */
function ledimov_admin_pages(){
    global $wpdb;
    $pt=$wpdb->prefix.'ledimov_properties';

    /* ── Salvar ── */
    if(isset($_POST['ledimov_save_page_id'])&&current_user_can('manage_options')&&check_admin_referer('ledimov_pages_save')){
        $page_id=intval($_POST['ledimov_save_page_id']);
        $content=wp_unslash($_POST['page_content']??'');
        $title=sanitize_text_field(wp_unslash($_POST['page_title']??''));
        $update=['ID'=>$page_id,'post_content'=>$content];
        if($title)$update['post_title']=$title;
        $result=wp_update_post($update);
        if($result&&!is_wp_error($result)){
            echo '<div class="notice notice-success is-dismissible"><p>✅ Página atualizada com sucesso!</p></div>';
        }else{
            echo '<div class="notice notice-error"><p>❌ Erro ao salvar a página.</p></div>';
        }
    }

    /* ── Coletar todas as páginas LedImov ── */
    $page_ids=[];
    foreach(['vitrine-imoveis','area-corretor'] as $slug){
        $p=get_page_by_path($slug);
        if($p)$page_ids[$p->ID]=$p->ID;
    }
    $props=$wpdb->get_results("SELECT id,title FROM {$pt}");
    foreach($props as $prop){
        $slug=sanitize_title($prop->title);
        $p=get_page_by_path($slug);
        if($p)$page_ids[$p->ID]=$p->ID;
    }
    $pages=$page_ids?get_posts(['include'=>array_values($page_ids),'post_type'=>'page','numberposts'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']):[];

    $edit_id=intval($_GET['edit_page']??0);
    $editing=$edit_id?get_post($edit_id):null;
    ?>
<div class="wrap ledimov-wrap">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
<h1 style="font-size:24px;font-weight:800;">📝 Editar Páginas do LedImov</h1>
<?php if($editing):?><a href="?page=ledimov-pages" class="li-btn li-btn-outline" style="text-decoration:none;">← Voltar à lista</a><?php endif;?>
</div>

<?php if($editing):?>
<!-- ── Formulário de edição ── -->
<div style="max-width:860px;">
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:14px;padding:28px;">
<h2 style="margin:0 0 20px;font-size:17px;font-weight:700;">Editando: <span style="color:var(--li-accent);"><?php echo esc_html($editing->post_title);?></span></h2>
<form method="post">
<?php wp_nonce_field('ledimov_pages_save');?>
<input type="hidden" name="ledimov_save_page_id" value="<?php echo $editing->ID;?>">
<div class="li-form-group">
    <label>Título da Página</label>
    <input class="li-input" name="page_title" value="<?php echo esc_attr($editing->post_title);?>">
</div>
<div class="li-form-group">
    <label>Conteúdo / Shortcodes</label>
    <textarea class="li-input" name="page_content" rows="14" style="font-family:monospace;font-size:13px;line-height:1.6;"><?php echo esc_textarea($editing->post_content);?></textarea>
    <small style="color:var(--li-muted);font-size:11px;margin-top:4px;display:block;">Edite o conteúdo e os shortcodes. As alterações serão refletidas para todos os usuários que acessarem a página.</small>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
    <button type="submit" class="li-btn li-btn-primary">💾 Salvar Página</button>
    <a href="<?php echo esc_url(get_permalink($editing->ID));?>" target="_blank" class="li-btn li-btn-outline" style="text-decoration:none;">🔗 Ver no site</a>
    <a href="<?php echo esc_url(get_edit_post_link($editing->ID));?>" target="_blank" class="li-btn li-btn-outline" style="text-decoration:none;">✏️ Editor WordPress</a>
</div>
</form>
</div>

<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:12px;padding:18px;margin-top:16px;">
<div style="font-size:12px;font-weight:700;color:var(--li-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.06em;">💡 Shortcodes disponíveis</div>
<div style="display:flex;flex-wrap:wrap;gap:8px;">
<?php $shortcodes=[
    '[ledimov_vitrine]'=>'Vitrine de empreendimentos',
    '[ledimov_area_corretor]'=>'Portal do corretor',
    '[ledimov_destaque]'=>'Empreendimentos em destaque',
    '[ledimov_tabela id="X"]'=>'Tabela de unidades',
    '[ledimov_mapa id="X"]'=>'Mapa do prédio',
    '[ledimov_card_imovel id="X"]'=>'Landing page do empreendimento',
];foreach($shortcodes as $sc=>$desc):?>
<div title="<?php echo esc_attr($desc);?>" style="background:var(--li-card-2);border:1px solid var(--li-border);border-radius:8px;padding:6px 10px;font-family:monospace;font-size:11px;cursor:pointer;" onclick="liCopyShortcode(this,'<?php echo esc_js($sc);?>')"><?php echo esc_html($sc);?></div>
<?php endforeach;?>
</div>
</div>
<script>function liCopyShortcode(el,sc){var ta=document.querySelector('textarea[name="page_content"]');if(ta){ta.focus();var pos=ta.selectionEnd;ta.value=ta.value.slice(0,pos)+sc+ta.value.slice(pos);ta.selectionStart=ta.selectionEnd=pos+sc.length;}}</script>
</div>

<?php else:?>
<!-- ── Lista de páginas ── -->
<?php if(empty($pages)):?>
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:12px;padding:28px;text-align:center;">
<p style="color:var(--li-muted);margin:0;">Nenhuma página LedImov encontrada. <a href="<?php echo admin_url('admin.php?page=ledimov-setup');?>" style="color:var(--li-accent);">Criar páginas →</a></p>
</div>
<?php else:?>
<p style="color:var(--li-muted);margin-bottom:16px;font-size:13px;">Edite o conteúdo e os shortcodes das páginas criadas pelo LedImov. As correções propagam imediatamente para todos os usuários.</p>
<div class="li-excel-wrap">
<table class="li-excel">
<thead><tr><th>Título</th><th>URL</th><th>Conteúdo</th><th>Status</th><th>Ações</th></tr></thead>
<tbody>
<?php foreach($pages as $page):$purl=get_permalink($page->ID);$snippet=substr(strip_tags($page->post_content),0,80);?>
<tr>
<td><strong><?php echo esc_html($page->post_title);?></strong></td>
<td><code style="font-size:11px;">/<?php echo esc_html($page->post_name);?></code></td>
<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace;font-size:11px;color:var(--li-muted);"><?php echo esc_html($snippet);?>...</td>
<td><?php echo $page->post_status==='publish'?'<span class="li-badge li-available">Publicada</span>':'<span class="li-badge li-blocked">'.esc_html($page->post_status).'</span>';?></td>
<td style="white-space:nowrap;">
<a href="?page=ledimov-pages&edit_page=<?php echo $page->ID;?>" class="li-btn li-btn-primary" style="font-size:11px;padding:5px 10px;text-decoration:none;">✏️ Editar</a>
<a href="<?php echo esc_url($purl);?>" target="_blank" class="li-btn li-btn-outline" style="font-size:11px;padding:5px 10px;text-decoration:none;">🔗 Ver</a>
</td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
<?php endif;?>
</div>
<?php }

/* ============================================================
   17. ADMIN – CONFIGURAÇÕES
   ============================================================ */
function ledimov_admin_settings(){$co=ledimov_get_company();?>
<div class="wrap ledimov-wrap">
<h1 style="font-size:24px;font-weight:800;margin-bottom:20px;">⚙️ Configurações da Empresa</h1>
<div style="max-width:700px;background:var(--li-card);border:1px solid var(--li-border);border-radius:14px;padding:28px;">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
<div class="li-form-group" style="grid-column:1/-1"><label>Nome da Empresa</label><input class="li-input" id="cfg-name" value="<?php echo esc_attr($co['name']);?>"></div>
<div class="li-form-group"><label>Tipo</label><select class="li-input" id="cfg-type"><option value="incorporadora" <?php selected($co['type'],'incorporadora');?>>Incorporadora</option><option value="imobiliaria" <?php selected($co['type'],'imobiliaria');?>>Imobiliária</option><option value="construtora" <?php selected($co['type'],'construtora');?>>Construtora</option></select></div>
<div class="li-form-group"><label>CNPJ</label><input class="li-input" id="cfg-cnpj" value="<?php echo esc_attr($co['cnpj']);?>"></div>
<div class="li-form-group"><label>CRECI PJ</label><input class="li-input" id="cfg-creci" value="<?php echo esc_attr($co['creci']);?>"></div>
<div class="li-form-group"><label>Endereço</label><input class="li-input" id="cfg-address" value="<?php echo esc_attr($co['address']);?>"></div>
<div class="li-form-group"><label>Cidade</label><input class="li-input" id="cfg-city" value="<?php echo esc_attr($co['city']);?>"></div>
<div class="li-form-group"><label>Estado (UF)</label><input class="li-input" id="cfg-state" value="<?php echo esc_attr($co['state']);?>"></div>
<div class="li-form-group"><label>Telefone</label><input class="li-input" id="cfg-phone" value="<?php echo esc_attr($co['phone']);?>"></div>
<div class="li-form-group"><label>WhatsApp (com DDI)</label><input class="li-input" id="cfg-whatsapp" value="<?php echo esc_attr($co['whatsapp']);?>" placeholder="5511999999999"></div>
<div class="li-form-group"><label>E-mail</label><input class="li-input" id="cfg-email" type="email" value="<?php echo esc_attr($co['email']);?>"></div>
<div class="li-form-group"><label>Site</label><input class="li-input" id="cfg-website" value="<?php echo esc_attr($co['website']);?>"></div>
<div class="li-form-group"><label>Instagram</label><input class="li-input" id="cfg-instagram" value="<?php echo esc_attr($co['instagram']);?>"></div>
<div class="li-form-group"><label>Facebook</label><input class="li-input" id="cfg-facebook" value="<?php echo esc_attr($co['facebook']);?>"></div>
<div class="li-form-group"><label>Cor Primária</label><input class="li-input" id="cfg-color_primary" type="color" value="<?php echo esc_attr($co['color_primary']?:'#c0392b');?>" style="height:42px;cursor:pointer;"></div>
<div class="li-form-group"><label>Cor Secundária</label><input class="li-input" id="cfg-color_secondary" type="color" value="<?php echo esc_attr($co['color_secondary']?:'#0e9f6e');?>" style="height:42px;cursor:pointer;"></div>
<div class="li-form-group" style="grid-column:1/-1"><label>Logo (URL)</label>
<div style="display:flex;gap:8px;align-items:flex-start;">
<div id="cfg-logo-preview" style="width:100px;height:60px;background:var(--li-card-2);border-radius:8px;border:2px dashed var(--li-border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;"><?php $lv=$co['logo_url'];if($lv):?><img src="<?php echo esc_url($lv);?>" style="width:100%;height:100%;object-fit:contain;"><?php else:?><span style="font-size:22px;">🏷</span><?php endif;?></div>
<div style="flex:1;"><div style="display:flex;gap:6px;margin-bottom:6px;"><button type="button" class="li-btn li-btn-primary" style="font-size:12px;" onclick="liPickImage('cfg-logo','cfg-logo-preview','contain')">📁 Biblioteca</button><button type="button" class="li-btn li-btn-outline" style="font-size:12px;" onclick="liPasteUrl('cfg-logo','cfg-logo-preview','contain')">🔗 URL</button></div>
<input type="text" class="li-input" id="cfg-logo" value="<?php echo esc_attr($co['logo_url']);?>" style="font-size:11px;" oninput="liPreviewFromInput('cfg-logo','cfg-logo-preview','contain')"></div>
</div></div>
<div class="li-form-group" style="grid-column:1/-1"><label>Slogan</label><input class="li-input" id="cfg-slogan" value="<?php echo esc_attr($co['slogan']);?>"></div>
<div class="li-form-group" style="grid-column:1/-1"><label>Sobre a Empresa</label><textarea class="li-input" id="cfg-about" rows="4"><?php echo esc_textarea($co['about']);?></textarea></div>
</div>
<div id="cfg-notice" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px;"></div>
<button class="li-btn li-btn-primary" style="margin-top:16px;" id="cfg-save-btn" onclick="liSaveSettings()">💾 Salvar Configurações</button>
</div>
</div>
<script>
function liSaveSettings(){var fields=['name','type','cnpj','creci','address','city','state','phone','whatsapp','email','website','instagram','facebook','color_primary','color_secondary','logo','slogan'];var data={};fields.forEach(function(f){var el=document.getElementById('cfg-'+f);if(el)data[f]=el.value;});var about=document.getElementById('cfg-about');if(about)data['about']=about.value;var box=document.getElementById('cfg-notice'),btn=document.getElementById('cfg-save-btn');if(btn){btn.disabled=true;btn.textContent='⏳...';}liAjax('ledimov_save_settings',data,function(err,res){if(btn){btn.disabled=false;btn.textContent='💾 Salvar Configurações';}if(!box)return;box.style.display='block';if(err||!res.success){box.style.background='#fdecea';box.style.color='#7b241c';box.textContent='❌ Erro.';}else{box.style.background='#d1fae5';box.style.color='#065f46';box.textContent='✅ Salvo!';}});}</script>
<?php }

/* ============================================================
   17. ADMIN – EXPORTAR / IMPORTAR
   ============================================================ */
function ledimov_admin_export_import(){?>
<div class="wrap ledimov-wrap">
<h1 style="font-size:24px;font-weight:800;margin-bottom:20px;">📦 Exportar / Importar</h1>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px;">
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:12px;padding:24px;">
<h3 style="margin:0 0 8px;font-size:16px;font-weight:700;">⬇️ Exportar Dados</h3>
<p style="color:var(--li-muted);font-size:13px;margin-bottom:16px;">Exporta todos os empreendimentos, unidades e corretores em JSON.</p>
<button class="li-btn li-btn-primary" onclick="liExportData()">⬇️ Exportar JSON</button>
</div>
<div style="background:var(--li-card);border:1px solid var(--li-border);border-radius:12px;padding:24px;">
<h3 style="margin:0 0 8px;font-size:16px;font-weight:700;">⬆️ Importar Dados</h3>
<p style="color:var(--li-muted);font-size:13px;margin-bottom:16px;">Cole o JSON exportado e clique em importar.</p>
<textarea class="li-input" id="li-import-json" rows="6" placeholder='{"properties":[...]}'></textarea>
<button class="li-btn li-btn-primary" style="margin-top:10px;" onclick="liImportData()">⬆️ Importar</button>
<div id="li-import-notice" style="display:none;margin-top:10px;padding:10px;border-radius:8px;font-size:13px;"></div>
</div></div></div>
<script>
function liExportData(){liAjax('ledimov_export_data',{},function(err,res){if(err||!res.success)return liToast('Erro ao exportar','error');var blob=new Blob([JSON.stringify(res.data,null,2)],{type:'application/json'});var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='ledimov_export_'+new Date().toISOString().slice(0,10)+'.json';a.click();});}
function liImportData(){var json=(document.getElementById('li-import-json')||{}).value.trim();if(!json)return liToast('Cole o JSON antes.','warning');try{JSON.parse(json);}catch(e){return liToast('JSON inválido.','error');}liAjax('ledimov_import_data',{json:json},function(err,res){var box=document.getElementById('li-import-notice');box.style.display='block';if(err||!res.success){box.style.background='#fdecea';box.style.color='#7b241c';box.textContent='❌ Erro: '+(res?res.data:'');}else{box.style.background='#d1fae5';box.style.color='#065f46';box.textContent='✅ '+(res.data.msg||'Importado!');setTimeout(function(){location.reload();},1500);}});}</script>
<?php }

add_action('wp_ajax_ledimov_export_data','ledimov_ajax_export_data');
function ledimov_ajax_export_data(){if(!current_user_can('manage_options'))wp_send_json_error('Acesso negado');global $wpdb;$data=array('properties'=>$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ledimov_properties"),'units'=>$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ledimov_units"),'brokers'=>$wpdb->get_results("SELECT id,name,email,phone,creci,agency,status FROM {$wpdb->prefix}ledimov_brokers"),'gallery'=>$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ledimov_gallery"),'exported_at'=>current_time('mysql'),'version'=>LEDIMOV_VERSION);wp_send_json_success($data);}

add_action('wp_ajax_ledimov_import_data','ledimov_ajax_import_data');
function ledimov_ajax_import_data(){if(!current_user_can('manage_options'))wp_send_json_error('Acesso negado');$json=stripslashes($_POST['json']??'');$data=json_decode($json,true);if(!$data)wp_send_json_error('JSON inválido');global $wpdb;$count=array('properties'=>0,'units'=>0,'brokers'=>0);if(!empty($data['properties'])){foreach($data['properties'] as $row){$row=(array)$row;unset($row['id']);$wpdb->insert($wpdb->prefix.'ledimov_properties',$row);$count['properties']++;}}if(!empty($data['units'])){foreach($data['units'] as $row){$row=(array)$row;unset($row['id']);$wpdb->insert($wpdb->prefix.'ledimov_units',$row);$count['units']++;}}wp_send_json_success(array('msg'=>'Importado: '.$count['properties'].' emprend., '.$count['units'].' unidades.','count'=>$count));}

/* ============================================================
   18. REST API
   ============================================================ */
add_action('rest_api_init','ledimov_register_rest');
function ledimov_register_rest(){
    register_rest_route('ledimov/v1','/properties',array('methods'=>'GET','callback'=>'ledimov_rest_properties','permission_callback'=>'__return_true'));
    register_rest_route('ledimov/v1','/properties/(?P<id>\d+)',array('methods'=>'GET','callback'=>'ledimov_rest_property','permission_callback'=>'__return_true'));
    register_rest_route('ledimov/v1','/properties/(?P<id>\d+)/units',array('methods'=>'GET','callback'=>'ledimov_rest_units','permission_callback'=>'__return_true'));
}
function ledimov_rest_properties($req){global $wpdb;$rows=$wpdb->get_results("SELECT id,title,address,neighborhood,city,state,cover_url,whatsapp,status FROM {$wpdb->prefix}ledimov_properties WHERE status='active' ORDER BY title");return rest_ensure_response($rows);}
function ledimov_rest_property($req){global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ledimov_properties WHERE id=%d AND status='active'",intval($req['id'])));if(!$row)return new WP_Error('not_found','Não encontrado',array('status'=>404));return rest_ensure_response($row);}
function ledimov_rest_units($req){global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT id,unit,floor,tower,bedrooms,area_util,price,entry_price,monthly_qty,monthly_price,status FROM {$wpdb->prefix}ledimov_units WHERE property_id=%d AND status='available' ORDER BY floor,unit",intval($req['id'])));return rest_ensure_response($rows);}

// LedImov v2.1.0 — fim do arquivo
