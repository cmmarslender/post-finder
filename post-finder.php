<?php

/**
 * Plugin Name: Post Finder
 * Author: Micah Ernst
 * Description: Adds a UI for currating and ordering posts
 * Version: 0.2
 */

if ( ! class_exists( 'NS_Post_Finder' ) ) :

define( 'POST_FINDER_VERSION', '0.2' );

/**
 * Namespacing the class with "NS" to ensure uniqueness
 */
class NS_Post_Finder {

	/**
	 * Setup hooks
	 *
	 * @return void
	 */
	function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'admin_footer', array( $this, 'admin_footer' ) );
		add_action( 'wp_ajax_pf_search_posts', array( $this, 'search_posts' ) );
	}

	/**
	 * Enable our scripts and stylesheets
	 *
	 * @return void
	 */
	function scripts() {

		wp_enqueue_script(
			'post-finder',
			\Cmmarslender\WPLibraryHelpers\library_url( 'js/main.js', __FILE__ ),
			array(
				'jquery',
				'jquery-ui-draggable',
				'jquery-ui-sortable',
				'underscore',
			),
			POST_FINDER_VERSION,
			true
		);

		wp_localize_script(
			'post-finder',
			'POST_FINDER_CONFIG',
			array(
				'adminurl'           => admin_url(),
				'nothing_found'      => esc_html__( 'No posts found.', 'post_finder' ),
				'max_number_allowed' => esc_html__( 'Sorry, the maximum number of items has been reached.', 'post_finder' ),
				'already_added'      => esc_html__( 'Sorry, that item has already been added.', 'post_finder' )
			)
		);

		wp_enqueue_style( 'post-finder', \Cmmarslender\WPLibraryHelpers\library_url( 'css/screen.css', __FILE__ ) );
	}

	/**
	 * Make sure our nonce and JS templates are on all admin pages
	 *
	 * @return void
	 */
	function admin_footer() {
		wp_nonce_field( 'post_finder', 'post_finder_nonce' );

		$this->render_js_templates();
	}

	/**
	 * A variant of wp_kses() that's safe for escaping Underscores templates.
	 *
	 * This acts as a wrapper around wp_kses(), first replacing common Underscores template tags with
	 * attribute-safe strings, then restoring the Underscores template tags when wp_kses() has run
	 * through all of $string.
	 *
	 * @param str   $string            Content to run through kses.
	 * @param array $allowed_html      Allowed HTML elements.
	 * @param array $allowed_protocols Allowed protocol in links.
	 * @return str The Underscores-ready template.
	 */
	public function underscores_safe_kses( $string, $allowed_html, $allowed_protocols = array() ) {

		// Escape Underscores
		$string = str_replace( '<%= ', '__UNDERSCORES_OPEN_ECHO_TAG__', $string );
		$string = str_replace( '<% ', '__UNDERSCORES_OPEN_TAG__', $string );
		$string = str_replace( ' %>', '__UNDERSCORES_CLOSE_TAG__', $string );

		$string = wp_kses( $string, $allowed_html, $allowed_protocols );

		// Restore Underscores
		$string = str_replace( '__UNDERSCORES_OPEN_ECHO_TAG__', '<%= ', $string );
		$string = str_replace( '__UNDERSCORES_OPEN_TAG__', '<% ', $string );
		$string = str_replace( '__UNDERSCORES_CLOSE_TAG__', ' %>', $string );

		return $string;
	}

	/**
	 * Outputs JS templates for use.
	 */
	private function render_js_templates() {
		$main_template =
			'<li data-id="<%= id %>">
				<div class="handle"></div>
				<div class="post">
					<span class="order">Order <input type="text" size="3" maxlength="3" max="3" value="<%= pos %>"></span>
					<span class="title"><%= title %></span>
					<nav>
						<a href="<%= edit_url %>" class="icon-pencil" target="_blank" title="Edit"><span class="label">Edit</span></a><a href="<%= permalink %>" class="icon-eye" target="_blank" title="View"><span class="label">View</span></a>
						<a href="#" class="icon-remove delete" title="Remove"><span class="label">Remove</span></a>
					</nav>
				</div>
			</li>';

		$item_template =
			'<li data-id="<%= ID %>" data-permalink="<%= permalink %>">
				<div class="post">
					<span class="title"><%= post_title %></span>
					<nav>
						<span class="status">Added</span>
						<a href="#" class="add"><span class="label">Add</span></a><a href="<%= permalink %>" class="view" target="_blank"><span class="label">View</span></a>
					</nav>
					<span class="date"><%= date %></span>
				</div>
			</li>';

		// allow for filtering / overriding of templates
		$main_template = apply_filters( 'post_finder_main_template', $main_template );
		$item_template = apply_filters( 'post_finder_item_template', $item_template );
		$allowed_html = array(
			'li' => array(
				'data-id' => true,
				'data-permalink' => true,
			),
			'input' => array(
				'type' => true,
				'size' => true,
				'maxlength' => true,
				'max' => true,
				'value' => true,
			),
			'a' => array(
				'href' => true,
				'class' => true,
				'target' => true,
				'title' => true,
			),
			'nav' => array(),
			'span' => array(
				'class' => true,
			),
			'div' => array(
				'class' => true,
			),
		);

		?>

		<script type="text/html" id="tmpl-post-finder-main">
		<?php
		// @codingStandardsIgnoreStart
		// Ignoring because this output is filterd in underscores_safe_kses()
		echo $this->underscores_safe_kses( $main_template, $allowed_html );
		// @codingStandardsIgnoreEnd ?>
		</script>

		<script type="text/html" id="tmpl-post-finder-item">
		<?php
		// @codingStandardsIgnoreStart
		// Ignoring because this output is filterd in underscores_safe_kses()
		echo $this->underscores_safe_kses( $item_template, $allowed_html );
		// @codingStandardsIgnoreEnd ?>
		</script>

		<?php
	}

	/**
	 * Builds an input that lets the user find and order posts
	 *
	 * @param string $name Name of input
	 * @param string $value Expecting comma seperated post ids
	 * @param array $options Field options
	 */
	public static function render( $name, $value, $options = array() ) {
		
		// Set a default value to not throw notices if no posts are selected
		$posts = array();
		
		$options = wp_parse_args( $options, array(
			'show_numbers'            => true, // display numbers next to post
			'show_icons'              => true, // show icon or text actions
			'show_recent_select_list' => true, // show select list for most recent posts (better for widgets)
			'limit'                   => 10,
			'include_script'          => true, // Should the <script> tags to init post finder be included or not
		));
		$options = apply_filters( 'post_finder_render_options', $options );

		// check to see if we have query args
		$args = isset( $options['args'] ) ? $options['args'] : array();

		// setup some defaults
		$args = wp_parse_args( $args, array(
			'post_type'        => 'post',
			'posts_per_page'   => 10,
			'post_status'      => 'publish',
			'suppress_filters' => false,
		));

		// now that we have a post type, figure out the proper label
		if( is_array( $args['post_type'] ) ) {
			$singular         = 'Item';
			$plural           = 'Items';
			$singular_article = 'an';
		} elseif( $post_type = get_post_type_object( $args['post_type'] ) ) {
			$singular         = $post_type->labels->singular_name;
			$plural           = $post_type->labels->name;
			$singular_article = 'a';
		} else {
			$singular         = 'Post';
			$plural           = 'Posts';
			$singular_article = 'a';
		}

		// get current selected posts if we have a value
		if( !empty( $value ) && is_string( $value ) ) {

			$post_ids = array_map( 'intval', explode( ',', $value ) );

			$posts = get_posts( array(
				'post_type'        => $args['post_type'],
				'post_status'      => $args['post_status'],
				'post__in'         => $post_ids,
				'orderby'          => 'post__in',
				'suppress_filters' => false,
				'posts_per_page'   => count( $post_ids )
			));
		}

		// if we have some ids already, make sure they arent included in the recent posts
		if( !empty( $post_ids ) ) {
			$args['post__not_in'] = $post_ids;
		}

		$class = 'post-finder';

		if( !$options['show_numbers'] ) {
			$class .= ' no-numbers';
		}

		if( !$options['show_icons'] ) {
			$class .= ' no-icons';
		} else {
			$class .= ' icons';
		}

		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-limit="<?php echo intval( $options['limit'] ); ?>" data-args='<?php echo wp_json_encode( $args ); ?>'>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<ul class="list">
				<?php

				if( !empty( $posts ) ) {
					$i = 1;
					foreach( $posts as $post ) {
						printf(
							'<li data-id="%s">' .
								'<div class="handle"></div>' .
								'<div class="post">' .
									'<span class="order">Order <input type="text" size="3" maxlength="3" max="3" value="%s"></span>' .
									'<span class="title">%s</span>' .
									'<nav>' .
										'<a href="%s" class="icon-pencil" target="_blank" title="Edit"><span class="label">Edit</span></a>' .
										'<a href="%s" class="icon-eye" target="_blank" title="View"><span class="label">View</span></a>' .
										'<a href="#" class="icon-remove delete" title="Remove"><span class="label">Remove</span></a>' .
									'</nav>' .
								'</div>' .
							'</li>',
							intval( $post->ID ),
							intval( $i ),
							esc_html( apply_filters( 'post_finder_item_label', $post->post_title, $post ) ),
							esc_url( get_edit_post_link( $post->ID ) ),
							esc_url( get_permalink( $post->ID ) )
						);
						$i++;
					}
				} else {
					echo '<p class="notice">No ' . esc_html( $plural ) . ' added.</p>';
				}

				?>
			</ul>

			<p class="counter">
				<?php printf( __( '<span class="current-count">%d</span> of <span class="max-count">%d</span> maximum items', 'post_finder' ), intval( count( $posts ) ), intval( $options['limit'] ) ); ?> <span class="message">You'll need to remove an item from the list before you can add another.</span>
			</p>

				<div class="search-container">

				<h2 class="add-item-heading">Add <?php echo esc_html( $singular_article ) . ' ' . esc_html( $singular ); ?></h2>

				<?php
				// get recent posts
				$recent_posts = get_posts( apply_filters( 'post_finder_' . $name . '_recent_post_args', $args ) );

				if( $recent_posts && true === $options['show_recent_select_list'] ) : ?>
					<p>
						<select>
							<option value="0">Choose <?php echo esc_html( $singular_article ) . ' ' . esc_html( $singular ); ?></option>
							<?php foreach( $recent_posts as $post ) : ?>
							<option value="<?php echo intval( $post->ID ); ?>" data-permalink="<?php echo esc_attr( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( apply_filters( 'post_finder_item_label', $post->post_title, $post ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php
				endif; ?>

				<div class="search">
					<labe for="search-field">Search <input type="text" placeholder="Enter a term or phrase" id="search-field"></labe>
					<button class="button">Search</button>

					<?php //if ( true !== $options['show_recent_select_list'] ) : ?>
						<div class="statuses">
							<div class="status">
								<span class="spinner"></span>
								<?php if ( true !== $options['show_recent_select_list'] ) : ?>
									<span class="status-label">Recent Content</span>
								<?php endif; ?>
								<span class="cancel"><a href="#">Cancel</a></span>
							</div>
							<div class="reset"><a href="#">View most recent content</a></div>
						</div>
					<?php //endif; ?>

					<?php
					$results_class = 'results';
					if( true !== $options['show_recent_select_list'] ) {
						$results_class .= ' full';
					} ?>

					<div class="results-container">
						<ul class="<?php echo esc_attr( $results_class ); ?>">
							<?php
							if( $recent_posts && true !== $options['show_recent_select_list'] ) :
								foreach( $recent_posts as $post ) : ?>
									<li data-id="<?php echo intval( $post->ID ); ?>" data-permalink="<?php echo esc_attr( get_permalink( $post->ID ) ); ?>">
										<div class="post">
											<span class="title"><?php echo esc_html( apply_filters( 'post_finder_item_label', $post->post_title, $post ) ); ?></span>
											<nav>
												<span class="status">Added</span>
												<a href="#" class="add"><span class="label">Add</span></a><a href="<?php echo esc_attr( get_permalink( $post->ID ) ); ?>" class="view" target="_blank"><span class="label">View</span></a>
											</nav>
											<span class="date"><?php echo esc_html( mysql2date( 'F j, Y', $post->post_date ) ); ?></span>
										</div>
									</li>
								<?php
								endforeach;
							endif; ?>
						</ul>
					</div>
				</div>
			</div>

		</div>
		<?php
		if ( $options['include_script'] ) {
			?>
			<script type="text/javascript">
				jQuery(document).ready(function($){
					$('.post-finder').postFinder();
				});
			</script>
			<?php
		}
	}

	/**
	 * Ajax callback to get posts that we may want to ad
	 *
	 * @return void
	 */
	function search_posts() {

		check_ajax_referer( 'post_finder' );

		if( !current_user_can( 'edit_posts' ) ) {
			return;
		}

		// possible vars we'll except
		$vars = array(
			's',
			'post_parent',
			'post_status',
		);

		$args = array();

		// clean the basic vars
		foreach( $vars as $var ) {
			if( isset( $_POST[ $var ] ) ) {
				if( is_array( $_POST[ $var ] ) ) {
					$args[$var] = array_map( 'sanitize_text_field', $_POST[ $var ] );
				} else {
					$args[$var] = sanitize_text_field( $_POST[ $var ] );
				}
			}
		}

		// this needs to be within a range
		if( isset( $_POST['posts_per_page'] ) ) {

			$num = intval( $_POST['posts_per_page'] );

			if( $num <= 0 ) {
				$num = 10;
			} elseif( $num > 100 ) {
				$num = 100;
			}

			$args['posts_per_page'] = $num;
		}

		// handle post type validation differently
		if( isset( $_POST['post_type'] ) ) {

			$post_types = get_post_types( array( 'public' => true ) );

			if( is_array( $_POST['post_type'] ) ) {

				foreach( $_POST['post_type'] as $type ) {

					if( in_array( $type, $post_types ) ) {
						$args['post_type'][] = $type;
					}
				}

			} else {

				if( in_array( $_POST['post_type'], $post_types ) ) {
					$args['post_type'] = $_POST['post_type'];
				}

			}
		}

		if ( isset( $_POST['tax_query'] ) ) {
			foreach( $_POST['tax_query'] as $current_tax_query ) {
				$args['tax_query'][] = array_map( 'sanitize_text_field', $current_tax_query );
			}
		}

		$args['suppress_filters'] = false;

		// allow search args to be filtered
		$posts = get_posts( apply_filters( 'post_finder_search_args', $args ) );

		// Get the additional data to pass to the template
		foreach( $posts as $key => $post ) {
			$posts[ $key ]->permalink = get_permalink( $post->ID );
			$posts[ $key ]->date = esc_html( mysql2date( 'F j, Y', $post->post_date ) );
		}

		$posts = apply_filters( 'post_finder_search_results', $posts );

		header('Content-type: text/json');
		die( wp_json_encode( array( 'posts' => $posts ) ) );
	}
}
new NS_Post_Finder();

/**
 * Help function to render a post finder input
 */
function pf_render( $name, $value, $options = array() ) {
	NS_Post_Finder::render( $name, $value, $options );
}

endif;
