<?php
/**
 * Plugin Name: 3flow WPML Media Deletion Fix
 * Description: Forces WPML Media Translation on, ensures physical file deletion completes correctly, and provides an orphan uploads checker.
 * Version:     1.2.0
 * Author:      3flow | Joao Victor Angeline
 */

/**
 * Safety net: enforce physical file deletion via wp_delete_file filter.
 */
add_filter( 'wp_delete_file', function( $file ) {
	if ( ! empty( $file ) && file_exists( $file ) ) {
		@unlink( $file );
	}
	return $file;
} );

/**
 * Admin notice: warn if WPML Media Translation is disabled.
 */
add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$wpml_media           = get_option( '_wpml_media', [] );
	$media_translation_on = ! empty( $wpml_media['should_handle_media_auto'] );

	if ( $media_translation_on ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>3flow:</strong> We noticed WPML Media Translation option is turned off. Turn it on to ensure the file deletion workflow works as expected. <br>Go to WP Admin > WPML > Settings > Media Translation.</p></div>';
} );

/**
 * Orphan Uploads Checker
 * Access via: /wp-admin/?check_orphan_uploads=1
 */

/**
 * Scans wp-content/uploads/YYYY/MM/ and returns orphan files
 * not referenced in the media library (excluding WPML duplicates).
 *
 * @return array List of orphans with 'post_title' and 'guid' keys.
 */
function threeflow_get_orphan_uploads() {
    global $wpdb;

    $db_files = $wpdb->get_col( "
        SELECT pm.meta_value
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_wp_attached_file'
        AND p.post_type = 'attachment'
        AND p.post_status = 'inherit'
        AND p.ID NOT IN (
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = 'wpml_media_processed'
            AND meta_value = '1'
        )
    " );

    $db_filenames = [];
    foreach ( $db_files as $path ) {
        $db_filenames[ strtolower( $path ) ]          = true;
        $db_filenames[ strtolower( basename( $path ) ) ] = true;
        $db_filenames[ strtolower( pathinfo( $path, PATHINFO_FILENAME ) ) ] = true;
    }

    $meta_rows = $wpdb->get_col( "
        SELECT pm.meta_value
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_wp_attachment_metadata'
        AND p.post_type = 'attachment'
        AND p.post_status = 'inherit'
        AND p.ID NOT IN (
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = 'wpml_media_processed'
            AND meta_value = '1'
        )
    " );

    foreach ( $meta_rows as $serialized ) {
        $meta = maybe_unserialize( $serialized );
        if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
            continue;
        }
        foreach ( $meta['sizes'] as $size ) {
            if ( ! empty( $size['file'] ) ) {
                $db_filenames[ strtolower( $size['file'] ) ] = true;
            }
        }
    }

    $uploads     = wp_upload_dir();
    $uploads_dir = $uploads['basedir'];
    $uploads_url = $uploads['baseurl'];
    $orphans     = [];

    $year_dirs = glob( $uploads_dir . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR );

    if ( ! empty( $year_dirs ) ) {
        foreach ( $year_dirs as $year_dir ) {
            $month_dirs = glob( $year_dir . '/[0-9][0-9]', GLOB_ONLYDIR );
            if ( empty( $month_dirs ) ) continue;

            foreach ( $month_dirs as $month_dir ) {
                $files = glob( $month_dir . '/*' );
                if ( empty( $files ) ) continue;

                foreach ( $files as $file ) {
                    if ( ! is_file( $file ) ) continue;

                    $filename      = strtolower( basename( $file ) );
                    $relative_path = strtolower( str_replace( $uploads_dir . '/', '', $file ) );

                    if ( preg_match( '/-\d+x\d+\.[a-z0-9]+$/i', $filename ) ) continue;
                    if ( isset( $db_filenames[ $relative_path ] ) )            continue;
                    if ( isset( $db_filenames[ $filename ] ) )                 continue;

                    $matched = false;
                    foreach ( $db_filenames as $known => $_ ) {
                        if ( strpos( $filename, $known ) === 0 ) {
                            $matched = true;
                            break;
                        }
                    }
                    if ( $matched ) continue;

                    $orphans[] = [
                        'post_title' => pathinfo( $file, PATHINFO_FILENAME ),
                        'guid'       => $uploads_url . '/' . str_replace( $uploads_dir . '/', '', $file ),
                    ];
                }
            }
        }
    }

    return $orphans;
}

add_action( 'admin_init', function () {
    if ( ! isset( $_GET['check_orphan_uploads'] ) || $_GET['check_orphan_uploads'] !== '1' ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    // Handle delete request
    if ( isset( $_GET['delete_orphans'] ) && $_GET['delete_orphans'] === '1' ) {
        check_ajax_referer( 'delete_orphans' );

        $uploads     = wp_upload_dir();
        $uploads_dir = $uploads['basedir'];
        $uploads_url = $uploads['baseurl'];
        $orphans     = threeflow_get_orphan_uploads();
        $deleted     = 0;

        foreach ( $orphans as $orphan ) {
            $relative = str_replace( trailingslashit( $uploads_url ), '', $orphan['guid'] );
            $filepath  = trailingslashit( $uploads_dir ) . $relative;
            if ( file_exists( $filepath ) && @unlink( $filepath ) ) {
                $deleted++;
            }
        }

        wp_send_json( [ 'deleted' => $deleted ] );
    }

    // Render report
    $orphans = threeflow_get_orphan_uploads();

	// 3. Output as HTML report
	$count = count( $orphans );
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
	<!doctype html>
	<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Orphan Uploads Report</title>
		<style>
			*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

			body {
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				font-size: 14px;
				background: #f5f5f5;
				color: #222;
				padding: 40px 24px;
			}

			.container {
				max-width: 1100px;
				margin: 0 auto;
			}

			h1 {
				font-size: 20px;
				font-weight: 600;
				margin-bottom: 4px;
			}

			.subtitle {
				color: #666;
				font-size: 13px;
				margin-bottom: 24px;
			}

			.summary {
				display: inline-block;
				padding: 6px 14px;
				border-radius: 4px;
				font-size: 13px;
				font-weight: 600;
				margin-bottom: 20px;
			}

			.summary.ok   { background: #e6f4ea; color: #1e7e34; border: 1px solid #b7dfbf; }
			.summary.warn { background: #fff8e1; color: #856404; border: 1px solid #ffe082; }

			/* Grid header */
			.grid-header {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 0;
				background: #f0f0f0;
				border: 1px solid #ddd;
				border-bottom: none;
				border-radius: 6px 6px 0 0;
				overflow: hidden;
			}

			.grid-header span {
				padding: 10px 16px;
				font-size: 12px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: .05em;
				color: #555;
			}

			.grid-header span:first-child {
				border-right: 1px solid #ddd;
			}

			/* Grid rows */
			.grid-body {
				border: 1px solid #ddd;
				border-radius: 0 0 6px 6px;
				overflow: hidden;
				background: #fff;
			}

			.grid-row {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 0;
				border-bottom: 1px solid #eee;
			}

			.grid-row:last-child {
				border-bottom: none;
			}

			.grid-row:nth-child(even) {
				background: #fafafa;
			}

			.grid-row:hover {
				background: #f0f7ff;
			}

			.grid-cell {
				padding: 10px 16px;
				overflow: hidden;
				word-break: break-word;
				min-width: 0;
			}

			.grid-cell:first-child {
				font-weight: 500;
				border-right: 1px solid #eee;
			}

			.grid-cell a {
				color: #1a73e8;
				text-decoration: none;
				word-break: break-all;
			}

			.grid-cell a:hover { text-decoration: underline; }

			.empty {
				padding: 40px;
				text-align: center;
				color: #666;
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 6px;
			}

			.back {
				display: inline-block;
				margin-top: 20px;
				font-size: 13px;
				color: #1a73e8;
				text-decoration: none;
			}

			.back:hover { text-decoration: underline; }
		</style>
	</head>
	<body>
		<div class="container">
			<h1>Orphan Uploads Report</h1>
			<p class="subtitle">Files found in <code>wp-content/uploads/YYYY/MM/</code> but not referenced in the media library.</p>

			<?php if ( empty( $orphans ) ) : ?>
				<span class="summary ok">&#10003; No orphan files found</span>
				<div class="empty">All files in the uploads directory are present in the database.</div>
			<?php else : ?>
				<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
                    <span class="summary warn" style="margin-bottom:0;">&#9888; <?php echo esc_html( $count ); ?> orphan file<?php echo $count !== 1 ? 's' : ''; ?> found</span>
                    <button id="copy-btn" onclick="copyList()" style="padding:6px 14px; font-size:13px; border:1px solid #ccc; border-radius:4px; background:#fff; cursor:pointer;">&#128203; Copy list</button>
                    <button id="delete-btn" onclick="deleteFiles()" style="padding:6px 14px; font-size:13px; border:1px solid #d9534f; border-radius:4px; background:#fff; color:#d9534f; cursor:pointer;">&#128465; Delete all</button>
                </div>

				<div class="grid-header">
					<span>File name</span>
					<span>File URL</span>
				</div>
				<div class="grid-body">
					<?php foreach ( $orphans as $orphan ) : ?>
						<div class="grid-row">
							<div class="grid-cell"><?php echo esc_html( $orphan['post_title'] ); ?></div>
							<div class="grid-cell"><a href="<?php echo esc_url( $orphan['guid'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $orphan['guid'] ); ?></a></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<a class="back" href="<?php echo esc_url( admin_url() ); ?>">&#8592; Back to Admin</a>
		</div>

     <script>
        function copyList() {
            const rows = document.querySelectorAll('.grid-row');
            const lines = Array.from( rows ).map( row => {
                const cells = row.querySelectorAll('.grid-cell');
                return cells[1].textContent.trim();
            });
            navigator.clipboard.writeText( lines.join('\n') ).then( () => {
                const btn = document.getElementById('copy-btn');
                btn.textContent = '✓ Copied!';
                setTimeout( () => btn.textContent = '📋 Copy list', 2000 );
            });
        }

        function deleteFiles() {
            if ( ! confirm( 'Are you sure you want to permanently delete all <?php echo $count; ?> orphan files from the server? This cannot be undone.' ) ) {
                return;
            }
            const btn = document.getElementById('delete-btn');
            btn.textContent = 'Deleting...';
            btn.disabled = true;
            fetch( '<?php echo esc_url( wp_nonce_url( admin_url() . "?check_orphan_uploads=1&delete_orphans=1", "delete_orphans" ) ); ?>' )
            .then( r => r.json() )
            .then( data => {
                    btn.textContent = "&#128465; Deleted " + data.deleted + " file(s)";
                    if ( data.deleted > 0 ) {
                        document.querySelectorAll('.grid-row').forEach( row => row.remove() );
                        document.querySelector('.summary').textContent = "&#10003; All orphans deleted";
                    }
            });
        }   
    </script>
	</body>
	</html>
	<?php
	exit;
} );