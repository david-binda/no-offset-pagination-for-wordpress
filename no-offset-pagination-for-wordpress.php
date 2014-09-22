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
		add_filter( 'posts_orderby', array(  $this, 'orderby'), 10, 2 );
		add_filter( 'posts_request', array( $this, 'posts_request' ), 10, 2 );
	}

	private function applies( $query, $direction = 'next' ) {
		return ( false === is_admin() && true === $query->is_main_query() && true === isset( $_GET[ $direction ] ) ) ? true : false;
	}

	public function where( $where, $query ) {
		if ( true === $this->applies( $query ) ) {
			global $wpdb;
			$post = get_post( intval( $_GET['next'] ) );
			$where .= $wpdb->prepare( " AND ( {$wpdb->posts}.post_date, {$wpdb->posts}.ID ) < ( %s, %d )", $post->post_date, $post->ID );
		}

		return $where;
	}

	public function limit( $limit, $query ) {
		if ( true === $this->applies( $query ) ) {
			global $wpdb;
			$limit = $wpdb->prepare( "LIMIT %d", $query->query_vars['posts_per_page'] );
		}

		return $limit;
	}

	public function orderby( $orderby, $query ) {
		if ( true === $this->applies( $query ) ) {
			global $wpdb;
			$orderby .= " , {$wpdb->posts}.ID DESC";
		}
		return $orderby;
	}

	public function posts_request( $request, $query ) {
		if ( true === $this->applies( $query, 'prev' ) ) {
			global $wpdb;
			$request = "SELECT * FROM (".$request.") ORDER BY {$wpdb->posts}.post_date DESC";
		}
		return $request;
	}

	private static function paginate_links( $args = '' ) {
		global $wp_query, $wp_rewrite;

		$total        = ( isset( $wp_query->max_num_pages ) ) ? $wp_query->max_num_pages : 1;
		$current      = ( get_query_var( 'paged' ) ) ? intval( get_query_var( 'paged' ) ) : 1;
		$pagenum_link = html_entity_decode( get_pagenum_link() );
		$query_args   = array();
		$url_parts    = explode( '?', $pagenum_link );
		$posts = $wp_query->posts;
		$last_post = array_pop( $posts );
		$last_post_id = ( null !== $last_post ) ? $last_post->ID : false;
		unset( $last_post );
		unset( $posts );

		if ( isset( $url_parts[1] ) ) {
			wp_parse_str( $url_parts[1], $query_args );
		}

		$pagenum_link = remove_query_arg( array_keys( $query_args ), $pagenum_link );
		$pagenum_link = trailingslashit( $pagenum_link ) . '%_%';

		$format = $wp_rewrite->using_index_permalinks() && ! strpos( $pagenum_link, 'index.php' ) ? 'index.php/' : '';
		$format .= $wp_rewrite->using_permalinks() ? user_trailingslashit( $wp_rewrite->pagination_base . '/%#%', 'paged' ) : '?paged=%#%';

		$defaults = array(
			'base'               => $pagenum_link,
			// http://example.com/all_posts.php%_% : %_% is replaced by format (below)
			'format'             => $format,
			// ?page=%#% : %#% is replaced by the page number
			'total'              => $total,
			'current'            => $current,
			'show_all'           => false,
			'prev_text'          => __( '&larr; Previous', 'nooffsetpagination' ),
			'next_text'          => __( 'Next &rarr;', 'nooffsetpagination' ),
			'end_size'           => 1,
			'mid_size'           => 2,
			'type'               => 'plain',
			'add_args'           => false,
			// array of query args to add
			'add_fragment'       => '',
			'before_page_number' => '',
			'after_page_number'  => ''
		);

		$args = wp_parse_args( $args, $defaults );

		// Who knows what else people pass in $args
		$total = (int) $args['total'];
		if ( $total < 2 ) {
			return;
		}
		$current  = (int) $args['current'];
		$add_args   = is_array( $args['add_args'] ) ? $args['add_args'] : false;
		$r          = '';
		$page_links = array();

		if ( $current && 1 < $current ) :
			$link = str_replace( '%_%', 2 == $current ? '' : $args['format'], $args['base'] );
			$link = str_replace( '%#%', $current - 1, $link );
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
		endif;
		if ( $current && ( $current < $total || - 1 == $total ) ) :
			$link = str_replace( '%_%', $args['format'], $args['base'] );
			$link = str_replace( '%#%', $current + 1, $link );
			$link = add_query_arg( array( 'next' => $last_post_id ), $link );
			if ( $add_args ) {
				$link = add_query_arg( $add_args, $link );
			}
			$link .= $args['add_fragment'];

			/** This filter is documented in wp-includes/general-template.php */
			$page_links[] = '<a class="next page-numbers" href="' . esc_url( apply_filters( 'paginate_links', $link ) ) . '">' . $args['next_text'] . '</a>';
		endif;
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
		global $wp_query, $wp_rewrite;

		// Don't print empty markup if there's only one page.
		if ( $wp_query->max_num_pages < 2 ) {
			return;
		}

		$paged        = get_query_var( 'paged' ) ? intval( get_query_var( 'paged' ) ) : 1;
		$pagenum_link = html_entity_decode( get_pagenum_link() );
		$query_args   = array();
		$url_parts    = explode( '?', $pagenum_link );

		if ( isset( $url_parts[1] ) ) {
			wp_parse_str( $url_parts[1], $query_args );
		}

		$pagenum_link = remove_query_arg( array_keys( $query_args ), $pagenum_link );
		$pagenum_link = trailingslashit( $pagenum_link ) . '%_%';

		$format = $wp_rewrite->using_index_permalinks() && ! strpos( $pagenum_link, 'index.php' ) ? 'index.php/' : '';
		$format .= $wp_rewrite->using_permalinks() ? user_trailingslashit( $wp_rewrite->pagination_base . '/%#%', 'paged' ) : '?paged=%#%';

		// Set up paginated links.
		$links = self::paginate_links( array(
			'base'     => $pagenum_link,
			'format'   => $format,
			'total'    => $wp_query->max_num_pages,
			'current'  => $paged
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