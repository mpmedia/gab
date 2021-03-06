<?php
/*
Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
 rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit
 persons to whom the Software is furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
 OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE
*/

require_once('gab_config.php');
class gab extends gab_config {
    /// // ////// /////// ////////////////////
    //     GAB - Tiny Forums by Andy Chase.

    // Page Controllers
    // Controllers are loaded left to right
    public $controllers = array(
        'posts' => array('new_thread.php', 'posts.php'),
        'single_category' => array('single_category.php', 'posts.php'),
        'single_post' => array('moderate.php', 'new_reply.php', 'single_post.php'),
        'categories' => array('new_category.php', 'categories.php'),
        'users' => array('users.php'),
        'single_user' => array('single_user.php'),
        'messages' => array('new_message.php', 'messages.php'),
        'ext' => array()
    );
    
    public $baseTemplate = 'base.tpl';
    public $templates = array(
        '*' => '',
        'posts' => '|posts.tpl',
        'single_category' => '|posts.tpl',
        'single_post' => '|single_post.tpl',
        'categories' => '|categories.tpl',
        'users' => '|users.tpl',
        'single_user' => '|single_user.tpl',
        'messages' => '|messages.tpl',
    );
    private function buildTemplate($page) {
        return 'extends:'.$this->baseTemplate.$this->templates['*'].$this->templates[$page];
    }

    // Internal Variables ////////////////////
    public $smarty;
    public $pdo;

    // List of pages provided by extensions
    private $extension_pages = array();
    // Current extension for each of these pages
    private $extension_pages_ext = array();
    // Name the extension running, this is what makes the api nice to use
    public $current_extension;
    // When a page is being processed, it is placed here
    private $current_page;
    // The id that allows us to have different caches for the same template
    private $cache_id = '';
    // Extensions can add to these lists to include stuff
    private $javascript = array();
    private $css = array();
    // If using location redirects, don't display page.
    public $redirect = false;
    // The user object of the current user accessing the page
    public $user;
    // Associative array of functions to call during certain events
    //   Structure: "name" => array($current_extension => callback)
    private $triggers = array();

    // Extension API /////////////////////////
    function addPage($page, $callback_function) {
        $this->extension_pages[$page] = $callback_function;
        $this->extension_pages_ext[$page] = $this->current_extension;
    }

    function addSmartyPlugin($plugin_type, $plugin_name, $function_name) {
        $this->smarty->registerPlugin($plugin_type, $plugin_name, $function_name);
    }

    function addTemplate($page, $template_name, $order='') {
        if ($order == 'pre')
            $this->templates[$page] =
                "|file:{$this->extensions_folder}/{$this->current_extension}/$template_name"
                 . $this->templates[$page];
        else
            $this->templates[$page] .=
                "|file:{$this->extensions_folder}/{$this->current_extension}/$template_name";
    }

    function addJavascript($name, $order='') {
        $path = '//'.
                $this->extensions_folder .
                DIRECTORY_SEPARATOR      .
                $this->current_extension .
                DIRECTORY_SEPARATOR . $name;
        if ($order == 'pre')
            array_unshift($this->javascript, $path);
        else
            $this->javascript[] = $path;
    }

    function addCss($name, $order='') {
        $path = '//'.
                $this->extensions_folder .
                DIRECTORY_SEPARATOR      .
                $this->current_extension .
                DIRECTORY_SEPARATOR . $name;
        if ($order == 'pre')
            array_unshift($this->css, $path);
        else
            $this->css[] = $path;
    }

    function getOption($name) {
        return $this->ext_options[$this->current_extension][$name];
    }

    function addOption($name, $default, $choices=null, $range_low=null, $range_right=null, $type=null) {
        if($this->getOption($name))
            $this->ext_options_config[$this->current_extension][$name] = array($this->getOption($name), $choices, $range_low, $range_right, $type);
        else
            $this->ext_options_config[$this->current_extension][$name] = array($default, $choices, $range_low, $range_right, $type);
    }

    function requireExt($name) {
        $calling_from_ext = $this->current_extension;
        $this->current_extension = $name;
        include_once($this->extensions_folder.
            DIRECTORY_SEPARATOR.
            $name.
            DIRECTORY_SEPARATOR.
            "$name.php");
        $this->current_extension = $calling_from_ext;
    }

    function bindTrigger($event_name, $callback) {
        $this->triggers[$event_name][] = array($this->current_extension, $callback);
    }

    function trigger($event_name, $object=null) {
        $calling_from_ext = $this->current_extension;
        if(array_key_exists($event_name, $this->triggers)) {
            foreach($this->triggers[$event_name] as $trigger) {
                $this->current_extension = $trigger[0];
                $callback = $trigger[1];
                if ($object) {
                    $return = $callback($this, $object);
                    if (!is_null($return))
                        $object = $return;
                } else {
                    $callback($this);
                }
            }
        }
        $this->current_extension = $calling_from_ext;
        return $object;
    }

    // Template //////////////////////////////
    function assign($var_name, $var) {
        $this->smarty->assign($var_name, $var);
    }

    function clearCache($page, $cache_id=null) {
        global $forum_id;
        if($cache_id) $cache_id = "|$cache_id";
        if ($page != null)
            $this->smarty->clearCache($this->buildTemplate($page), "{$forum_id}{$cache_id}");
        else
            $this->smarty->clearCache(null, "{$forum_id}{$cache_id}");
    }

    function isCached() {
        global $forum_id;
        return $this->smarty->isCached($this->buildTemplate($this->current_page), "{$forum_id}|{$this->cache_id}");
    }

    function displayGeneric($template) {
        $this->addTemplate('posts', $template);
        $this->smarty->caching = 0;
        $this->prepare_static(true);
        $this->smarty->display($this->buildTemplate('posts'));
    }

    function addCacheId($id) {
        $this->cache_id = "$id|".$this->cache_id;
    }

    function parse($text) {
        $text = htmlspecialchars($text);
        return $this->trigger(gab_triggers::PARSE, $text);
    }

    function avatar($email_hash, $size=40, $default_style='retro') {
        return "http://www.gravatar.com/avatar/{$email_hash}?s={$size}&d={$default_style}";
    }

    function prepare_static($skip_caching=false) {
        // Prepare javascript and css list & hash.
        // Why?:
        //   Basic way of hiding what extensions you are using
        //   The list can get kinda long, has to be served on each request
        if ($skip_caching || !$this->isCached()) {
            $js_hash = hash('md4', implode('/',$this->javascript));
            $css_hash = hash('md4', implode('/',$this->css));
            if(!is_file('min/groups/'.$js_hash.'.php'))
                file_put_contents('min/groups/'.$js_hash, serialize(array($js_hash => $this->javascript)));
            if(!is_file('min/groups/'.$css_hash.'.php'))
                file_put_contents('min/groups/'.$css_hash, serialize(array($css_hash => $this->css)));
            $this->assign('js_url', '/min/?g='.$js_hash);
            $this->assign('css_url', '/min/?g='.$css_hash);
        }
    }

    function prepare_user($id, $name, $email_hash, $badges) {
        $this->user = new gab_user($id, $name, $email_hash, $badges);
        $this->assign('logged_in', $id != null);
        $this->addCacheId('r:'.$this->user->permissionHash());
        $this->assign('user_logged_in', $this->user);
    }
    
    function gab(Smarty $smarty, $pdo) {
        $GLOBALS['forum_id'] = $this->forum_id;

        $smarty->setTemplateDir($this->templates_folder);
        $this->smarty = $smarty;
        $this->pdo = $pdo;

        $this->addSmartyPlugin('modifier', 'avatar', array($this, 'avatar'));
        $this->addSmartyPlugin('modifier', 'parse',  array($this, 'parse'));

        // Prepare Extensions ////////////////////////////
        $gab = $this;
        foreach($this->ext as $name) {
            $this->current_extension = $name;
            include_once($this->extensions_folder .
                DIRECTORY_SEPARATOR . $name .
                DIRECTORY_SEPARATOR . "$name.php"
            );
        }
    }

    function run($page, $matches, $user_id, $user_email_hash, $user_name, $badges) {
        $this->assign('base_url', $this->base_url);
        $this->assign('ext_url', $this->base_url . '/' . $this->extensions_folder);
        $this->assign('forum_name', $this->forum_name);
        $this->assign('forum_id', $this->forum_id);
        $this->assign('forum_desc', $this->forum_description);
        $perm = new ReflectionClass('permission');
        $this->assign('permissions', $perm->getConstants());
        $this->current_page = $page;

        $this->prepare_user($user_id, $user_name, $user_email_hash, $badges);
        // Load models
        require_once($this->model_folder.DIRECTORY_SEPARATOR."model.php");
        // Page triggers
        $this->trigger('*');
        $this->trigger($page);
        // Load controllers
        foreach($this->controllers[$page] as $controller)
            require($this->controller_folder.DIRECTORY_SEPARATOR.$controller);
        $this->trigger('post_'.$page);

        if ($page == 'ext' && array_key_exists($matches[1], $this->extension_pages)) {
            $this->current_extension = $this->extension_pages_ext[$matches[1]];
            call_user_func_array($this->extension_pages[$matches[1]], array($this));
        } else {
            if (!$GLOBALS['testing'] && $this->redirect) return false;
            $this->prepare_static();
            $this->smarty->display($this->buildTemplate($page), "{$this->forum_id}|{$this->cache_id}");
        }
        return false;
    }
}
// Trigger Enum //// //////////////////////////////////
class gab_triggers {
    const PARSE = 'parse';
    const AVATAR = 'avatar';
}

// User object //// ///////////////////////////////////
class gab_user {
    public $id;
    public $name;
    public $email_hash;
    public $badges;
    function __construct($id, $name, $email_hash, $badges) {
        $this->id = $id;
        $this->name = $name;
        $this->email_hash = $email_hash;
        if ($badges)
            foreach($badges as $badge)
                $this->badges[$badge] = true;
    }
    function isOwn($author, $visibility) {
        if ($visibility && $author &&
            permission::MODIFY_OWN == '*' &&
            $visibility == 'normal' &&
            $author == $this->id)
            return true;
        else
            return false;
    }
    function hasPermission($permission, $category=null, $author=null, $visibility=null) {
        return $this->badges[$permission]  == true ||
            $this->isOwn($author, $visibility) ||
            $this->badges[$category . '_' . $permission] == true;
    }
    function permissionHash() {
        // Gets a number or string representing the unique permissions this user has
        // For caching purposes
        if ($this->badges['mod'] && $this->badges['owner']) return 3;
        if ($this->badges['owner']) return 2;
        if ($this->badges['mod']) return 1;
        if ($this->id) return 0;
        else return -1;
    }
}
// ////// /////// //////////////////////////////////////