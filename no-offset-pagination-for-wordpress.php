<?php
/**
 * Plugin Name: No Offset Pagination for WordPress
 * Plugin URI: https://github.com/david-binda/no-offset-pagination-for-wordpress
 * Description: Introduces No Offset (keyset) pagination approach to WordPress
 * Version: 0.1
 * Author: David Biňovec
 * Author URI: http://david.binda.cz
 * License: GPL2
 */

register_activation_hook( __FILE__, 'no_offset_pagination_activation_hook' );

function no_offset_pagination_activation_hook() {
	global $wpdb;
	$wpdb->query( "CREATE INDEX post_date ON {$wpdb->posts} (post_date)" );
}

/**
 * Template tag for printing the pagination
 */
function no_offset_pagination() {
	NoOffsetPagination::pagination();
}

class NoOffsetPagination {

	public function __construct() {
		//todo: can't we use "posts_where_paged" filter?
		add_filter( 'posts_where', array( $this, 'where' ), 10, 2 );
		add_filter( 'post_limits', array( $this, 'limit' ), 10, 2 );
		add_filter( 'posts_orderby', array( $this, 'orderby' ), 10, 2 );
		add_filter( 'posts_request', array( $this, 'posts_request' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ), 10, 2 );
	}

	private static function applies( $query, $direction = 'both' ) {
		$applies = false;
		if ( false === is_admin() ) {
			if ( true === $query->is_main_query() && ( true === isset( $_GET[ 'next' ] ) || true === isset( $_GET['prev'] ) ) ) {
				if ( 'both' === $direction ) {
					$applies = true;
				} else if ( true === isset( $_GET[ $direction ] ) ) {
					$applies = true;
				}
			} else {
				if ( true === isset( $query->query_vars['nooffset'] ) && false === empty( $query->query_vars['nooffset'] ) ) {
					if ( 'both' === $direction ) {
						$applies = true;
					} else if ( true === isset( $query->query_vars['nooffset'][ $direction ] ) ) {
						$applies = true;
					}
				}
			}
		}

		return $applies;
	}

	private static function get_direction( $query ) {
		$direction = 'next';
		if ( true === $query->is_main_query() && true === isset( $_GET['prev'] ) ) {
			$direction = 'prev';
		} else {
			if ( true === isset( $query->query_vars['nooffset'] ) && false === empty( $query->query_vars['nooffset'] ) ) {
				if ( true === isset( $query->query_vars['nooffset'][ 'prev' ] ) ) {
					$direction = 'prev';
				}
			}
		}
		return $direction;
	}

	private static function get_post_id( $query = null ) {
		$direction = self::get_direction( $query );
		if ( true === isset( $_GET[ $direction ] ) ) {
			return intval( $_GET[ $direction ] );
		} else if ( true === isset( $query->query_vars['nooffset'] ) && false === empty( $query->query_vars['nooffset'] ) ) {
			return $query->query_vars['nooffset'][ $direction ];
		}
		return null;
	}

	private static function reverse_order( $order ) {
		if ('DESC' === $order ) {
			$order = 'ASC';
		} else {
			$order = 'DESC';
		}
		return $order;
	}

	private static function get_order( $query, $direction = null ) {
		$direction = self::get_direction( $query );
		if ( true === isset( $query->query_vars['order'] ) && false === empty( $query->query_vars['order'] ) ) {
			$order = $query->query_vars['order'];
		} else{
			$order = 'DESC';
		}
		if ( 'prev' === $direction ) {
			$order = self::reverse_order( $order );
		}
		return $order;
	}

	public function where( $where, $query ) {
		if ( true === self::applies( $query ) ) {
			global $wpdb;
			$post = get_post( self::get_post_id( $query ) );
			if ( false === empty( $post ) ) {
				$order = self::get_order( $query );
				$operator = ( 'DESC' !== $order ) ? '>' : '<';
				$where .= $wpdb->prepare( " AND ( {$wpdb->posts}.post_date, {$wpdb->posts}.ID ) {$operator} ( %s, %d )", $post->post_date, $post->ID );
			}
		}

		return $where;
	}

	public function limit( $limit, $query ) {
		if ( true === self::applies( $query ) ) {
			global $wpdb;
			$limit = $wpdb->prepare( "LIMIT %d", $query->query_vars['posts_per_page'] );
		}

		return $limit;
	}

	private static function get_orderby_param( $query ) {
		return ( true === isset( $query->query_vars['orderby'] ) && false === empty( $query->query_vars['orderby'] ) ) ? $query->query_vars['orderby'] : 'post_date';
	}

	public function orderby( $orderby, $query ) {
		if ( true === self::applies( $query ) ) {
			global $wpdb;
			$order = self::get_order( $query );
			$orderby_param = self::get_orderby_param( $query );
			$orderby = "{$wpdb->posts}.{$orderby_param} {$order}, {$wpdb->posts}.ID {$order}";
		}

		return $orderby;
	}

	public function posts_request( $request, $query ) {
		if ( true === self::applies( $query, 'prev' ) ) {
			$orderby_param = self::get_orderby_param( $query );
			$order = self::get_order( $query, 'prev' );
			$order = self::reverse_order( $order );
			$request = "SELECT * FROM (" . $request . ") as results ORDER BY results.{$orderby_param} {$order}, results.ID {$order}";
		}

		return $request;
	}

	public function pre_get_posts( $query ) {
		if ( true === self::applies( $query ) ) {
			$query->set( 'no_found_rows', true );
		}
	}

	private static function get_last_post_id() {
		global $wp_query;
		$posts        = $wp_query->posts;
		$last_post    = array_pop( $posts );
		$last_post_id = ( null !== $last_post ) ? $last_post->ID : false;
		return $last_post_id;
	}

	private static function get_first_post_id() {
		global $wp_query;
		$posts = $wp_query->posts;
		$first_post = array_shift( $posts );
		$first_post_id = ( null !== $first_post ) ? $first_post->ID : false;
		return $first_post_id;
	}

	private static function paginate_links( $args = '' ) {
		global $wp_rewrite;

		$pagenum_link = html_entity_decode( get_pagenum_link() );
		$query_args   = array();
		$url_parts    = explode( '?', $pagenum_link );

		if ( isset( $url_parts[1] ) ) {
			wp_parse_str( $url_parts[1], $query_args );
		}

		$pagenum_link = remove_query_arg( array_keys( $query_args ), $pagenum_link );
		$pagenum_link = trailingslashit( $pagenum_link );

		$format = $wp_rewrite->using_index_permalinks() && ! strpos( $pagenum_link, 'index.php' ) ? 'index.php/' : '';
		$format .= $wp_rewrite->using_permalinks() ? user_trailingslashit( $wp_rewrite->pagination_base, 'paged' ) : '?paged=%#%';

		$defaults = array(
			'base'               => $pagenum_link,
			'format'             => $format,
			'show_all'           => false,
			'prev_text'          => __( '&larr; Previous', 'nooffsetpagination' ),
			'next_text'          => __( 'Next &rarr;', 'nooffsetpagination' ),
			'type'               => 'plain',
			'add_args'           => false,
			// array of query args to add
			'add_fragment'       => '',
			'before_page_number' => '',
			'after_page_number'  => ''
		);

		$args = wp_parse_args( $args, $defaults );

		$add_args   = is_array( $args['add_args'] ) ? $args['add_args'] : false;
		$r          = '';
		$page_links = array();

		if ( ( true === isset( $_GET['next'] ) && false === empty( $_GET['next'] ) ) || ( true === isset( $_GET['prev'] ) && false === empty( $_GET['prev'] ) ) ) {
			$link = $args['base'];
			$first_post_id = self::get_first_post_id();
			$link = add_query_arg( array( 'prev' => $first_post_id ), $link );
			if ( $add_args ) {
				$link = add_query_arg( $add_args, $link );
			}
			$link .= $args['add_fragment'];

			/**
			 * Filter the paginated links for the given archive pages.
			 *
			 * @since 3.0.0
			 *
			 * @param string $link The paginated link URL.
			 */
			$page_links[] = '<a class="prev page-numbers" href="' . esc_url( apply_filters( 'paginate_links', $link ) ) . '">' . $args['prev_text'] . '</a>';
		}

		$link = $args['base'];
		$last_post_id = self::get_last_post_id();
		$link = add_query_arg( array( 'next' => $last_post_id ), $link );
		if ( $add_args ) {
			$link = add_query_arg( $add_args, $link );
		}
		$link .= $args['add_fragment'];

		/** This filter is documented in wp-includes/general-template.php */
		$page_links[] = '<a class="next page-numbers" href="' . esc_url( apply_filters( 'paginate_links', $link ) ) . '">' . $args['next_text'] . '</a>';


		switch ( $args['type'] ) {
			case 'array' :
				return $page_links;

			case 'list' :
				$r .= "<ul class='page-numbers'>\n\t<li>";
				$r .= join( "</li>\n\t<li>", $page_links );
				$r .= "</li>\n</ul>\n";
				break;

			default :
				$r = join( "\n", $page_links );
				break;
		}

		return $r;
	}

	public static function pagination() {
		global $wp_rewrite;

		$pagenum_link = html_entity_decode( get_pagenum_link() );
		$query_args   = array();
		$url_parts    = explode( '?', $pagenum_link );

		if ( isset( $url_parts[1] ) ) {
			wp_parse_str( $url_parts[1], $query_args );
		}

		$pagenum_link = remove_query_arg( array_keys( $query_args ), $pagenum_link );
		$pagenum_link = trailingslashit( $pagenum_link );

		$format = $wp_rewrite->using_index_permalinks() && ! strpos( $pagenum_link, 'index.php' ) ? 'index.php/' : '';
		$format .= $wp_rewrite->using_permalinks() ? user_trailingslashit( $wp_rewrite->pagination_base, 'paged' ) : '?paged=%#%';

		// Set up paginated links.
		$links = self::paginate_links( array(
			'base'   => $pagenum_link,
			'format' => $format
		) );

		if ( $links ) :

			?>
			<nav class="navigation paging-navigation" role="navigation">
				<h1 class="screen-reader-text"><?php _e( 'Posts navigation', 'nooffsetpagination' ); ?></h1>

				<div class="pagination loop-pagination">
					<?php echo $links; ?>
				</div>
				<!-- .pagination -->
			</nav><!-- .navigation -->
		<?php
		endif;
	}
}

new NoOffsetPagination();