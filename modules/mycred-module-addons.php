<?php
if ( !defined( 'myCRED_VERSION' ) ) exit;
/**
 * myCRED_Addons class
 * @since 0.1
 * @version 1.0
 */
if ( !class_exists( 'myCRED_Addons' ) ) {
	class myCRED_Addons extends myCRED_Module {

		/**
		 * Construct
		 */
		function __construct() {
			parent::__construct( 'myCRED_Addons', array(
				'module_name' => 'addons',
				'option_id'   => 'mycred_pref_addons',
				'defaults'    => array(
					'installed'     => array(),
					'active'        => array()
				),
				'labels'      => array(
					'menu'        => __( 'Add-ons', 'mycred' ),
					'page_title'  => __( 'Add-ons', 'mycred' ),
					'page_header' => __( 'Add-ons', 'mycred' )
				),
				'screen_id'   => 'myCRED_page_addons',
				'accordion'   => true,
				'menu_pos'    => 30
			) );
		}

		/**
		 * Run Addons
		 * Catches all add-on activations and deactivations and loads addons
		 * @since 0.1
		 * @version 1.0
		 */
		public function module_pre_init() {
			$addons = $this->addons;
			$active = $addons['active'];
			$installed = $this->get();
			$num = 0;

			// Make sure each active add-on still exists. If not delete.
			if ( !empty( $active ) ) {
				$active = array_unique( $active );
				$_active = array();
				foreach ( $active as $pos => $active_id ) {
					if ( array_key_exists( $active_id, $installed ) ) {
						$_active[] = $active_id;
						$num = $num+1;
					}
				}
				unset( $active );
				$active = $_active;
				$this->active = $active;
			}

			// Handle actions
			if ( isset( $_GET['addon_action'] ) && isset( $_GET['addon_id'] ) ) {
				$addon_id = $_GET['addon_id'];
				$action = $_GET['addon_action'];

				// Activation
				if ( $action == 'activate' ) {
					$active[$num] = $addon_id;
				}

				// Deactivation
				if ( $action == 'deactivate' ) {
					$index = array_search( $addon_id, $active );
					if ( $index !== false ) {
						unset( $active[$index] );
					}

					// Run deactivation now before the file is no longer included
					do_action( 'mycred_addon_deactivation_' . $addon_id );
				}

				$new_settings = array(
					'installed'   => $installed,
					'active'      => $active
				);

				if ( !function_exists( 'update_option' ) )
					include_once( ABSPATH . 'wp-includes/option.php' );
				
				update_option( 'mycred_pref_addons', $new_settings );
				$this->addons = $new_settings;
				$this->installed = $installed;
				$this->active = $active;
			}

			// Load addons
			foreach ( $installed as $key => $data ) {
				if ( $this->is_active( $key ) ) {
					// Include
					include_once( $this->get_path( $key ) );

					// Check for activation
					if ( $this->is_activation( $key ) ) do_action( 'mycred_addon_activation_' . $key );
				}
			}
		}

		/**
		 * Is Activation
		 * @since 0.1
		 * @version 1.0
		 */
		public function is_activation( $key ) {
			if ( isset( $_GET['addon_action'] ) && isset( $_GET['addon_id'] ) && $_GET['addon_action'] == 'activate' && $_GET['addon_id'] == $key )
				return true;
			
			return false;
		}

		/**
		 * Is Deactivation
		 * @since 0.1
		 * @version 1.0
		 */
		public function is_deactivation( $key ) {
			if ( isset( $_GET['addon_action'] ) && isset( $_GET['addon_id'] ) && $_GET['addon_action'] == 'deactivate' && $_GET['addon_id'] == $key )
				return true;
			
			return false;
		}

		/**
		 * Get Addons
		 * @since 0.1
		 * @version 1.0
		 */
		public function get( $save = false ) {
			$prefix = 'myCRED-addon-';
			$addon_location = myCRED_ADDONS_DIR;

			$installed = array();
			// Search for addons. should be in addons/*/myCRED-addon-*.php
			$addon_search = glob( $addon_location . "*/$prefix*.php" );
			if ( !empty( $addon_search ) && $addon_search !== false ) {
				foreach ( $addon_search as $filename ) {
					$sub_file = str_replace( ABSPATH, '', $filename );
					// Get File Name
					preg_match( '/(.{1,})\/(.{1,})/', $sub_file, $matches );
					$sub_file_name = $matches[2];
					// Prevent Duplicates
					if ( !array_key_exists( $sub_file_name, $installed ) )
						$installed[$this->make_id($sub_file_name)] = $this->get_addon_info( $filename, $matches[1], $sub_file_name );
				}
			}
			unset( $addon_search );
			$installed = apply_filters( 'mycred_setup_addons', $installed );

			if ( $save === true && $this->core->can_edit_plugin() ) {
				$new_data = array(
					'active'    => $this->active,
					'installed' => $installed
				);
				update_option( 'mycred_pref_addons', $new_data );
			}

			$this->installed = $installed;
			return $installed;
		}

		/**
		 * Make ID
		 * @since 0.1
		 * @version 1.0
		 */
		public function make_id( $id ) {
			$id = str_replace( 'myCRED-addon-', '', $id );
			$id = str_replace( '.php', '', $id );
			$id = str_replace( '_', '-', $id );
			return $id;
		}

		/**
		 * Get Addon Info
		 */
		public function get_addon_info( $file = false, $folder = false, $sub = '' ) {
			if ( !$file ) return;
			// Details we want
			$addon_details = array(
				'name'        => 'Addon',
				'addon_uri'   => 'Addon URI',
				'version'     => 'Version',
				'description' => 'Description',
				'author'      => 'Author',
				'author_uri'  => 'Author URI'
			);
			$addon_data = get_file_data( $file, $addon_details );

			$addon_data['file'] = $sub;
			if ( $folder )
				$addon_data['folder'] = $folder . '/';
			else
				$addon_data['folder'] = '';

			return $addon_data;
		}

		/**
		 * Get Path of Addon
		 * @since 0.1
		 * @version 1.0
		 */
		public function get_path( $key ) {
			$installed = $this->installed;
			if ( array_key_exists( $key, $installed ) ) {
				$file = $installed[$key]['file'];
				return ABSPATH . $installed[$key]['folder'] . $file;
			}
			return '';
		}

		/**
		 * Admin Page
		 * @since 0.1
		 * @version 1.0
		 */
		public function admin_page() {
			// Security
			if ( !$this->core->can_edit_plugin( get_current_user_id() ) ) wp_die( __( 'Access Denied' ) );

			// Get installed
			$installed = $this->get( true );

			// Message
			if ( isset( $_GET['addon_action'] ) && isset( $_GET['token'] ) ) {
				if ( $_GET['addon_action'] == 'activate' && wp_verify_nonce( $_GET['token'], 'myCRED-activate-addon' ) )
					echo '<div class="updated"><p>' . __( 'Add-on Activated', 'mycred' ) . '</p></div>';
				elseif ( $_GET['addon_action'] == 'deactivate' && wp_verify_nonce( $_GET['token'], 'myCRED-deactivate-addon' ) )
					echo '<div class="error"><p>' . __( 'Add-on Deactivated', 'mycred' ) . '</p></div>';
			} ?>

	<div class="wrap" id="myCRED-wrap">
		<div id="icon-myCRED" class="icon32"><br /></div>
		<h2><?php echo '<strong>my</strong>CRED ' . __( 'Add-ons', 'mycred' ); ?></h2>
		<p><?php _e( 'Add-ons can expand your current installation with further features.', 'mycred' ); ?></p>
		<div class="list-items expandable-li" id="accordion">
<?php
			// Loop though installed
			if ( !empty( $installed ) ) {
				foreach ( $installed as $key => $data ) { ?>

			<h4 class="<?php if ( $this->is_active( $key ) ) echo 'active'; else echo 'inactive'; ?>"><label><?php echo $this->core->template_tags_general( $data['name'] ); ?></label></h4>
			<div class="body" style="display:none;">
				<div class="wrapper">
						<?php $this->present_addon( $key ); ?>

				</div>
			</div>
<?php
				}
			} ?>

		</div>
	</div>
<?php
			unset( $this );
		}

		/**
		 * Activate / Deactivate Button
		 * @since 0.1
		 * @version 1.0
		 */
		public function activate_deactivate( $key ) {
			$url = admin_url( 'admin.php' );
			$args = array(
				'page'     => 'myCRED_page_addons',
				'addon_id' => $key
			);
			if ( $this->is_active( $key ) ) {
				$args['addon_action'] = 'deactivate';
				
				$link_title = __( 'Deactivate Add-on', 'mycred' );
				$link_text = __( 'Deactivate', 'mycred' );
			}
			else {
				$args['addon_action'] = 'activate';
				
				$link_title = __( 'Activate Add-on', 'mycred' );
				$link_text = __( 'Activate', 'mycred' );
			}

			return '<a href="' . add_query_arg( $args, $url ) . '" title="' . $link_title . '" class="button button-large button-primary mycred-action">' . $link_text . '</a>';
		}

		/**
		 * Add-on Details
		 * @since 0.1
		 * @version 1.0
		 */
		public function addon_links( $key ) {
			$data = $this->installed[$key];

			// Add-on Details
			$info = array();
			if ( isset( $data['version'] ) )
				$info[] = __( 'Version', 'mycred' ) . ' ' . $data['version'];

			if ( isset( $data['author_uri'] ) && !empty( $data['author_uri'] ) && isset( $data['author'] ) && !empty( $data['author'] ) )
				$info[] = __( 'By', 'mycred' ) . ' <a href="' . $data['author_uri'] . '" title="' . __( 'View Authors Website', 'mycred' ) . '">' . $data['author'] . '</a>';

			if ( isset( $data['addon_uri'] ) && !empty( $data['addon_uri'] ) )
				$info[] = ' <a href="' . $data['addon_uri'] . '" title="' . __( 'View Add-ons Website', 'mycred' ) . '">' . __( 'Visit Website', 'mycred' ) . '</a>';

			unset( $data );
			if ( !empty( $info ) )
				return implode( ' | ', $info );
			else
				return $info;
		}

		/**
		 * Preset Add-on details
		 * @since 0.1
		 * @version 1.0
		 */
		public function present_addon( $key ) {
			$addon_data = $this->installed[$key]; ?>

					<div class="description h2"><?php echo $this->core->template_tags_general( $addon_data['description'] ); ?></div>
					<p class="links"><?php echo $this->addon_links( $key ); ?></p>
					<p><?php echo $this->activate_deactivate( $key ); ?></p>
					<div class="clear">&nbsp;</div>
<?php
		}
	}
}
?>