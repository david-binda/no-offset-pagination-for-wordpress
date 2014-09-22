No Offset Pagination for WordPress
==================================

This is an experiment trying to implement **keyset pagination** aka *No offset pagination* as it was proposed by Markus Winand ( @fatalmind ) on http://use-the-index-luke.com/no-offset to **WordPress**

[![Do not use offset for pagination. Learn why.](http://use-the-index-luke.com/img/no-offset-banner-728x90.white.png)](http://use-the-index-luke.com/no-offset)

## Template tags usage


The plugin provides custom template tag for displaying next/prev navigation on archive pages (eg.: index.php or archive.php).

```
no_offset_pagination();
```

It should be used instead of standard ``wp_link_pages();`` or ``twentyfourteen_paging_nav();`` in case you are working with default Twentyfourteen template.

## Custom plugin / functions.php usage

You can also take advantage of this plugin in your custom development efforts in your plugins or theme functions.php file.

You just have to define extra query_vars for [WP_Query](http://codex.wordpress.org/Class_Reference/WP_Query). 

(__Please note:__ _you still must have this plugin installed before ``nooffset`` param is taken into consideration_)

```php
$args = array(
  'post_type' => 'post',
  'post_status' => 'publish',
  'posts_per_page' => 10,
  'nooffset' => array( 'next' => $last_displayed_post_id ) //this is the plugin's specific query_vars definition
);
$query = new WP_Query( $args );
$posts = $query->get_posts();
foreach ( $posts as $post ) {
  ...
}
```
