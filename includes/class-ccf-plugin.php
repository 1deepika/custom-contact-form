<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCF_Plugin {

	const RATE_LIMIT_SECONDS = 60;
	const ADMIN_PER_PAGE     = 20;

	public function __construct() {
		add_shortcode( 'custom_contact_form', array( $this, 'render_form' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_ccf_submit', array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_ccf_submit', array( $this, 'handle_submission' ) );
	}

	public function render_form() {
		$status = isset( $_GET['ccf_status'] ) ? sanitize_key( wp_unslash( $_GET['ccf_status'] ) ) : '';
		$code   = isset( $_GET['ccf_code'] ) ? sanitize_key( wp_unslash( $_GET['ccf_code'] ) ) : '';

		if ( $status ) {
			nocache_headers();
		}

		ob_start();
		?>
		<div class="ccf-form-wrap">
			<?php $this->render_notice( $status, $code ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<p>
					<label for="ccf_name"><?php esc_html_e( 'Name', 'custom-contact-form' ); ?></label><br>
					<input type="text" id="ccf_name" name="ccf_name" maxlength="100" required>
				</p>

				<p>
					<label for="ccf_email"><?php esc_html_e( 'Email', 'custom-contact-form' ); ?></label><br>
					<input type="email" id="ccf_email" name="ccf_email" maxlength="190" required>
				</p>

				<p>
					<label for="ccf_message"><?php esc_html_e( 'Message', 'custom-contact-form' ); ?></label><br>
					<textarea id="ccf_message" name="ccf_message" rows="5" required></textarea>
				</p>

				<p class="ccf-hp" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;">
					<label for="ccf_hp"><?php esc_html_e( 'Leave this field empty', 'custom-contact-form' ); ?></label>
					<input type="text" id="ccf_hp" name="ccf_hp" value="" tabindex="-1" autocomplete="off">
				</p>

				<?php wp_nonce_field( 'ccf_submit_form', 'ccf_nonce' ); ?>
				<input type="hidden" name="action" value="ccf_submit">
				<input type="hidden" name="ccf_redirect" value="<?php echo esc_url( $this->get_form_page_url() ); ?>">
				<button type="submit"><?php esc_html_e( 'Send Message', 'custom-contact-form' ); ?></button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public function handle_submission() {
		$honeypot = isset( $_POST['ccf_hp'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ccf_hp'] ) ) ) : '';
		if ( '' !== $honeypot ) {
			$this->redirect_with_status( 'success' );
		}

		if (
			! isset( $_POST['ccf_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['ccf_nonce'] ) ),
				'ccf_submit_form'
			)
		) {
			$this->redirect_with_status( 'error', 'security' );
		}

		if ( $this->is_rate_limited() ) {
			$this->redirect_with_status( 'error', 'rate' );
		}

		$name    = isset( $_POST['ccf_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ccf_name'] ) ) : '';
		$email   = isset( $_POST['ccf_email'] ) ? sanitize_email( wp_unslash( $_POST['ccf_email'] ) ) : '';
		$message = isset( $_POST['ccf_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ccf_message'] ) ) : '';

		if ( '' === $name || ! is_email( $email ) || '' === $message ) {
			$this->redirect_with_status( 'error', 'invalid' );
		}

		global $wpdb;
		$table  = $this->get_table_name();
		$result = $wpdb->insert(
			$table,
			array(
				'name'       => $name,
				'email'      => $email,
				'message'    => $message,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			$this->redirect_with_status( 'error', 'insert' );
		}

		$this->set_rate_limit();
		$this->redirect_with_status( 'success' );
	}

	public function admin_menu() {
		add_menu_page(
			__( 'Contact Submissions', 'custom-contact-form' ),
			__( 'Contact Submissions', 'custom-contact-form' ),
			'manage_options',
			'ccf-submissions',
			array( $this, 'admin_page' ),
			'dashicons-email-alt'
		);
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'custom-contact-form' ),
				esc_html__( 'Access denied', 'custom-contact-form' ),
				array( 'response' => 403 )
			);
		}

		global $wpdb;

		$table       = $this->get_table_name();
		$per_page    = self::ADMIN_PER_PAGE;
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$offset      = ( $paged - 1 ) * $per_page;
		$total       = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		if ( $paged > $total_pages ) {
			$paged  = $total_pages;
			$offset = ( $paged - 1 ) * $per_page;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, email, message, created_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				$offset
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Contact Submissions', 'custom-contact-form' ); ?></h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'custom-contact-form' ); ?></th>
						<th><?php esc_html_e( 'Email', 'custom-contact-form' ); ?></th>
						<th><?php esc_html_e( 'Message', 'custom-contact-form' ); ?></th>
						<th><?php esc_html_e( 'Date', 'custom-contact-form' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $rows ) : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->name ); ?></td>
							<td><?php echo esc_html( $row->email ); ?></td>
							<td><?php echo esc_html( $row->message ); ?></td>
							<td><?php echo esc_html( $row->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( 'No submissions yet.', 'custom-contact-form' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total > 0 ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: current page number, 2: total pages, 3: total submissions */
						esc_html__( 'Page %1$d of %2$d (%3$d submissions)', 'custom-contact-form' ),
						(int) $paged,
						(int) $total_pages,
						(int) $total
					);
					?>
				</p>
			<?php endif; ?>
			<?php
			if ( $total_pages > 1 ) {
				$pagination = paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $total_pages,
						'current'   => $paged,
						'type'      => 'plain',
					)
				);

				if ( $pagination ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo wp_kses_post( $pagination );
					echo '</div></div>';
				}
			}
			?>
		</div>
		<?php
	}

	private function render_notice( $status, $code ) {
		if ( 'success' === $status ) {
			echo '<p class="ccf-notice ccf-notice-success" role="status">' . esc_html__( 'Thank you. Your message has been sent.', 'custom-contact-form' ) . '</p>';
			return;
		}

		if ( 'error' !== $status ) {
			return;
		}

		$messages = array(
			'invalid'  => __( 'Please enter valid form details.', 'custom-contact-form' ),
			'insert'   => __( 'Something went wrong while saving your message. Please try again.', 'custom-contact-form' ),
			'rate'     => __( 'Please wait a moment before submitting again.', 'custom-contact-form' ),
			'security' => __( 'Security check failed. Please reload the page and try again.', 'custom-contact-form' ),
		);

		$message = isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Something went wrong. Please try again.', 'custom-contact-form' );

		echo '<p class="ccf-notice ccf-notice-error" role="alert">' . esc_html( $message ) . '</p>';
	}

	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ccf_submissions';
	}

	private function get_form_page_url() {
		$url = get_permalink();
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		return $url;
	}

	private function get_redirect_url() {
		$fallback = home_url( '/' );
		$url      = '';

		if ( isset( $_POST['ccf_redirect'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['ccf_redirect'] ) );
		}

		if ( ! $url ) {
			$referer = wp_get_referer();
			$url     = $referer ? $referer : $fallback;
		}

		$url = wp_validate_redirect( $url, $fallback );
		return remove_query_arg( array( 'ccf_status', 'ccf_code' ), $url );
	}

	private function redirect_with_status( $status, $code = '' ) {
		$args = array( 'ccf_status' => $status );
		if ( $code ) {
			$args['ccf_code'] = $code;
		}

		wp_safe_redirect( add_query_arg( $args, $this->get_redirect_url() ) );
		exit;
	}

	private function get_client_ip() {
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( $ip ) {
				return $ip;
			}
		}
		return 'unknown';
	}

	private function get_rate_limit_key() {
		return 'ccf_rl_' . md5( $this->get_client_ip() );
	}

	private function is_rate_limited() {
		return (bool) get_transient( $this->get_rate_limit_key() );
	}

	private function set_rate_limit() {
		set_transient( $this->get_rate_limit_key(), 1, self::RATE_LIMIT_SECONDS );
	}
}
