<?php
/*
    Plugin Name: Widgetmaster Widget Cache
    Description: Caches widget output in WordPress transient cache.
    Version: 0.5
    Plugin URI: https://github.com/lophas/widget-cache
    GitHub Plugin URI: https://github.com/lophas/widget-cache
    Author: Attila Seres
    Author URI:
*/

if (!class_exists('widget_cache')) :
class widget_cache
{
    const CACHE_ID = __CLASS__;
    private $cache;
    private static $_instance;
    public function instance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance =  new self();
        }
        return self::$_instance;
    }
    protected function __construct()
    {
        if (is_admin()) {
            add_action('in_widget_form', array( $this, 'in_widget_form' ), 10, 3);
            add_filter('widget_update_callback', array($this,'widget_update_callback'), 10, 4);
            add_action('sidebar_admin_setup', array($this,'widget_deleted_action'));
        } else {
            add_filter('widget_display_callback', array( $this, 'widget_display_callback' ), PHP_INT_MAX-1, 3);
        }

        add_action('init', array($this,'init'));
        add_action('save_post', [$this,'flush_cache']);
        add_action('deleted_post', [$this,'flush_cache']);
        add_action('trashed_post', [$this,'flush_cache']);
        add_action('edited_term', [$this,'flush_cache']);
        add_action('delete_term', [$this,'flush_cache']);
        add_action('wp_update_nav_menu', [$this,'flush_cache']);
        add_action('activated_plugin', [$this,'flush_cache']);
        add_action('deactivated_plugin', [$this,'flush_cache']);
        add_action('after_switch_theme', [$this,'flush_cache']);
        add_action('update_option_my_plugins_list', [$this,'flush_cache']);
        add_action('update_option_my_widgets_list', [$this,'flush_cache']);

        $diskcache = dirname(__FILE__).'/'.substr(basename(__FILE__), 0, -4).'/diskcache.class.php';
        if (file_exists($diskcache)) {
            $dirs = wp_upload_dir();
            $cachedir = $dirs['basedir'].'/'.__CLASS__;
            if (!file_exists($cachedir)) {
                mkdir($cachedir);
            }
            require_once($diskcache);
            $this->cache = new diskcache($cachedir);
        }
    }


    public function init()
    {
        if (is_admin_bar_showing()) {
            if (current_user_can('edit_theme_options')) {
                add_action('admin_bar_menu', array($this,'admin_bar_menu'), 150);
                //   if($_GET['clear_widget_cache']) self::flush_cache();
                if (isset($_GET['clear_widget_cache'])) {
                    $this->flush_cache();
                }
            }
        }
    }
    public function admin_bar_menu($wp_admin_bar)
    {
        if (!$wp_admin_bar->get_node('my-cache')) {
            $wp_admin_bar->add_menu(array(
      'id' => 'my-cache',
      'parent' => false,
      'title' => __('My Cache'),
      'href' => false,
      'meta' => array(),
   ));
        }
        $wp_admin_bar->add_menu(array(
      'id' => 'widget-cache',
      'parent' => 'my-cache',
      'title' => __('Clear Widget Cache'),
      'href' => add_query_arg('clear_widget_cache', true),
      'meta' => array(),
   ));
    }


    public function set_cache($cache_key, $cached_widget, $cache_time)
    {
        if (isset($this->cache)) {
            return $this->cache->set($cache_key, $cached_widget, $cache_time);
        } else {
            return set_transient(
                $cache_key,
                $cached_widget,
                $cache_time
            );
        }
    }

    public function get_cache($cache_key)
    {
        if (isset($this->cache)) {
            return $this->cache->get($cache_key);
        } else {
            return get_transient($cache_key);
        }
    }

    public function delete_cache($cache_key)
    {
        if (isset($this->cache)) {
            return $this->cache->delete($cache_key);
        } else {
            return delete_transient($cache_key);
        }
    }

    public function flush_cache()
    {
        if (defined('DOING_AUTOSAVE')) {
            return;
        }
        if (isset($this->cache)) {
            return $this->cache->flush();
        } else {
            global $wpdb;
            $wpdb->query('DELETE FROM '.$wpdb->options.' WHERE option_name LIKE ("%_transient_'.self::CACHE_ID.'%") OR option_name LIKE ("%_transient_timeout_'.self::CACHE_ID.'%")');
        }
    }

    public function widget_display_callback($instance, $widget, $args)
    {
        // Don't return the widget
        //		if ( false === $instance || ! is_subclass_of( $widget, 'WP_Widget' ) || is_a( $widget, 'WP_Widget_Recent_Posts_multi' ) ) return $instance;
        if (false === $instance || ! is_subclass_of($widget, 'WP_Widget')) {
            return $instance;
        }

        if (!$cache_time    =  $instance['cache_time']) {
            ob_start();
            $widget->widget($args, $instance);
            $content = ob_get_clean();
            echo apply_filters('widget_content', $content, $args, $instance, $widget);
            return false;// We already echoed the widget, so return false
        }

        $timer_start = microtime(true);
        $cache_key = sprintf(self::CACHE_ID.'-%s', $widget->id);

        if ($cached_widget = $this->get_cache($cache_key)) {
            $comment = '<!-- From widget cache start %s --> %s <!-- From widget cache end in %s seconds (%s) -->';
        } else {
            ob_start();
            $widget->widget($args, $instance);
            $cached_widget = ob_get_clean();
            $this->set_cache(
              $cache_key,
              $cached_widget,
              $cache_time
            );
            $comment = '<!-- Stored in widget cache start %s --> %s <!-- Stored in widget cache in %s seconds (%s) -->';
        }
        printf(
                $comment,
                $widget->id,
                apply_filters('widget_content', $cached_widget, $args, $instance, $widget),
                round(microtime(true) - $timer_start, 4),
                $cache_key
            );


        return false;// We already echoed the widget, so return false
    }

    public function widget_update_callback($instance, $new_instance, $old_instance, $widget)
    {
        //		if ( false === $instance || ! is_subclass_of( $widget, 'WP_Widget' ) || is_a( $widget, 'WP_Widget_Recent_Posts_multi' ) ) return $instance;
        if (false === $instance || ! is_subclass_of($widget, 'WP_Widget')) {
            return $instance;
        }
        $cache_key = sprintf(self::CACHE_ID.'-%s', $widget->id);
        $this->delete_cache($cache_key);
        //   if(!array_key_exists('cache',$instance) && !array_key_exists('cache',$new_instance))
        if (isset($new_instance['cache_time'])) {
            $instance['cache_time'] = absint($new_instance['cache_time']);
        }
        return $instance;
    }

    public function widget_deleted_action()
    {
        if ('post' == strtolower($_SERVER['REQUEST_METHOD'])) {
            if ($widget_id = $_POST['widget-id']) {
                if (isset($_POST['delete_widget'])) {
                    if (1 === (int) $_POST['delete_widget']) {
                        $cache_key = sprintf(self::CACHE_ID.'-%s', $widget_id);
                        $this->delete_cache($cache_key);
                    }
                }
            }
        }
    }


    public function in_widget_form($widget, $return, $instance)
    {
        //		if ( false === $instance || ! is_subclass_of( $widget, 'WP_Widget' ) || is_a( $widget, 'WP_Widget_Recent_Posts_multi' ) ) return;
        if (false === $instance || ! is_subclass_of($widget, 'WP_Widget')) {
            return;
        }
        $cache_time    = isset($instance['cache_time']) ? absint($instance['cache_time']) : 0; ?>
<p>
		<label for="<?php echo $widget->get_field_id('cache_time'); ?>"><?php _e('Cache time:'); ?></label>
		<input class="small-text" id="<?php echo $widget->get_field_id('cache_time'); ?>" name="<?php echo $widget->get_field_name('cache_time'); ?>" type="number" step="1" min="0" value="<?php echo $cache_time; ?>" size="3" />s (0:disable)
</p>
<?php
    }
}
widget_cache::instance();
endif;
