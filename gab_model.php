<?php

class forum {

    static function get_posts($category=null, $sort=null, $sort_down=true, $show_hidden=false) {
        global $pdo;
        $q = "
            SELECT
              forum.id,
              forum.title,
              category.title AS category,
              forum.author_name,
              forum.author_email_hash,
              replies.reply_num  AS replies,
              forum.views,
              Coalesce(replies.last_reply, forum.timestamp) AS last_reply
            FROM forum forum
            LEFT JOIN (
                 SELECT Count(*) AS reply_num, Max(replies.`timestamp`) AS last_reply, replies.reply_to
                 FROM   forum AS replies
                 WHERE  replies.`type` = 'reply'
                 AND replies.`hidden` = 'N'
                 GROUP  BY replies.reply_to
            ) replies ON id = replies.reply_to
            LEFT JOIN forum category ON forum.reply_to = category.id

        ";
        if ($sort == "author")
            $q .= "
                LEFT JOIN (
                SELECT id, count(*) as contributions
                FROM forum
                GROUP BY author
                ) author ON author.id = forum.author";


        $q .= " WHERE  forum.type = 'post' ";
        if ($category)
            $q .= " AND category.title = ? ";
        $q .= " AND forum.hidden = 'N' ";

        if ($sort == "category")
            $q .= " ORDER BY - RAND() * LOG((NOW() - forum.timestamp))";
        else if ($sort == "title")
            $q .= " ORDER BY title";
        else if ($sort == "views")
            $q .= " ORDER BY forum.views";
        else if ($sort == "posts")
            $q .= " ORDER BY replies.reply_num";
        else if ($sort == "author")
            $q .= " ORDER BY author.contributions";
        else
            $q .= " ORDER BY last_reply";

        if($sort_down) $q .= " DESC";
        else $q .= " ASC";

        $statement = $pdo->prepare($q);

        if ($category == null) $statement->execute();
        else $statement->execute(array($category));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    static function get_post($post_id, $show_hidden=false) {
        global $pdo;
        $hideq = " AND forum.`hidden` = 'N' ";
        if ($show_hidden) $hideq = "";
        $q = "
            SELECT
              forum.`id`,
              forum.`title`,
              forum.`message`,
              forum.`timestamp`,
              forum.`author_name`,
              forum.`author_email_hash`,
              replies.reply_num,
              category.title as 'category',
              forum.`hidden`
            FROM forum forum
            LEFT JOIN (
                   SELECT count(*) as reply_num, reply_to
                   FROM forum as replies
                   WHERE replies.`type` = 'reply'
                   GROUP BY replies.reply_to
                   ) replies ON id = replies.reply_to
            LEFT JOIN forum category on forum.`reply_to` = category.`id`
            WHERE forum.`type` = 'post'
            AND forum.`id` = ?
            $hideq
            LIMIT 1
        ";

        $statement = $pdo->prepare($q);
        $statement->execute(array($post_id));
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    static function get_replies($post_id, $skip=0, $show_hidden=false) {
        global $pdo;
        $hideq = " AND forum.`hidden` = 'N' ";
        if ($show_hidden) $hideq = "";
        $q = "
            SELECT
              forum.`id`,
              forum.`title`,
              forum.`message`,
              forum.`timestamp`,
              forum.`author_name`,
              forum.`author_email_hash`,
              forum.`hidden`
            FROM forum forum
            WHERE forum.`type` = 'reply'
            AND forum.`reply_to` = ?
            $hideq
            ORDER BY forum.`id` ASC
            LIMIT ?, 60
        ";

        $statement = $pdo->prepare($q);
        $statement->execute(array($post_id, $skip));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function add_view($post_id)
    {
        global $pdo;
        $q = "
          UPDATE forum
          SET forum.`views` = forum.`views` + 1
          WHERE forum.`id` = ?
        ";
        $statement = $pdo->prepare($q);
        $statement->execute(array($post_id));
    }

    public static function new_category($author, $author_name, $author_email_hash, $title, $description)
    {
        global $pdo;
        $q = "
            INSERT INTO  `forum` (
                `type` ,
                `author` ,
                `author_name` ,
                `author_email_hash` ,
                `title`,
                `message`,
                `reply_to`
                )
            VALUES ('category', ?, ?, ?, ?, ?, NULL);
        ";
        $statement = $pdo->prepare($q);
        $statement->execute(array($author, $author_name, $author_email_hash, $title, $description));
        return $pdo->lastInsertId();
    }

    public static function post_reply($topic_id, $user_id, $user_name, $user_email_hash, $text)
    {
        global $pdo;
        $q = "
            INSERT INTO  `forum` (
                `type` ,
                `reply_to` ,
                `author` ,
                `author_name` ,
                `author_email_hash` ,
                `message`
                )
            VALUES ('reply', ?, ?, ?, ?, ?);
        ";

        $statement = $pdo->prepare($q);
        $statement->execute(array($topic_id, $user_id, $user_name, $user_email_hash, $text));
        return $pdo->lastInsertId();
    }

    public static function new_thread($user_id, $user_name, $user_email_hash, $title, $text, $cat=null)
    {
        global $pdo;

        if ($cat) {
            $statement = $pdo->prepare("
                SELECT id FROM forum WHERE type = 'category' AND title = ?
            ");
            $statement->execute(array($cat));
            $cat = $statement->fetchColumn();
        } else {
            $cat = null;
        }

        $q = "
            INSERT INTO  `forum` (
                `type` ,
                `author` ,
                `author_name` ,
                `author_email_hash` ,
                `title`,
                `message`,
                `reply_to`
                )
            VALUES ('post', ?, ?, ?, ?, ?, ?);
        ";

        $statement = $pdo->prepare($q);
        $statement->execute(array($user_id, $user_name, $user_email_hash, $title, $text, $cat));
        return $pdo->lastInsertId();
    }

    public static function get_messages($user_name) {
        global $pdo;
        $q = "
            SELECT mention.type, mention.reply_to, mention.id, mention.author_name, mention.message, mention.timestamp, category.title
            FROM forum mention
            LEFT JOIN forum category on mention.reply_to = category.id
            WHERE mention.message LIKE ?
            UNION
            SELECT mention.type, mention.reply_to, mention.id, mention.author_name, mention.message, mention.timestamp, category.title
            FROM forum mention
            LEFT JOIN forum category on mention.reply_to = category.id
            WHERE mention.message LIKE ?
            UNION
            SELECT mention.type, mention.reply_to, mention.id, mention.author_name, mention.message, mention.timestamp, category.title
            FROM forum mention
            LEFT JOIN forum category on mention.reply_to = category.id
            WHERE mention.message LIKE ?
            ORDER BY timestamp DESC
            ";
        $statement = $pdo->prepare($q);
        $statement->execute(array("@${user_name}\r\n%", "@${user_name}\n%", "@${user_name} %"));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    static function get_post_category($category_id) {
        global $pdo;
        $q = "
            SELECT  forum.`id`,
                    forum.`title`,
                    replies.reply_num as replies,
                    COALESCE(replies.last_reply, forum.timestamp) as last_reply
            FROM forum forum
            LEFT JOIN
                (
                   SELECT count(*) as reply_num,
                          MAX(replies.`timestamp`) as last_reply,
                          replies.reply_to
                   FROM forum replies
                   WHERE replies.`type` = 'reply'
                   AND replies.`hidden` = 'N'
                   GROUP BY replies.reply_to
               )  replies ON id = replies.reply_to
            WHERE forum.`type` = 'post'
            AND forum.hidden = 'N'
            AND forum.reply_to
            ";

        if ($category_id == "") $q .= "is null";
        else $q .= "= ?";

        $q .= " ORDER BY last_reply DESC";

        $statement = $pdo->prepare($q);
        if ($category_id == null) $statement->execute();
        else $statement->execute(array($category_id));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    static function get_categories() {
        global $pdo;
        $q = "
          SELECT category.id, category.title, category.message, posts.number_of_posts
          FROM forum category
          LEFT JOIN (
              SELECT posts.reply_to, count(*) as number_of_posts
              FROM forum posts
              WHERE posts.type = 'post'
              AND hidden = 'N'
              GROUP BY reply_to

            ) posts on posts.reply_to = category.id
          WHERE category.type = 'category'
          ORDER BY posts.number_of_posts DESC
        ";
        $statement = $pdo->prepare($q);
        $statement->execute();
        $categories = $statement->fetchAll(PDO::FETCH_ASSOC);

        $categories[''] = array("id" => "", "title" => "", "message" => "");
        foreach ($categories as $id => $category) {
            $categories[$id]['posts'] = self::get_post_category($category['id']);
        }
        return $categories;
    }

    static function get_category_list() {
        global $pdo;
        $statement = $pdo->prepare("SELECT title FROM forum WHERE type = 'category'");
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    static function get_category($id) {
        global $pdo;
        $statement = $pdo->prepare("SELECT title FROM forum WHERE type = 'category' and id = ?");
        $statement->execute(array($id));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    static function get_user($user_title) {
        global $pdo;
        $q = "
             SELECT id, title, author, author_name, author_email_hash
             FROM forum user
             WHERE title = ?
             AND type = 'user'
             LIMIT 1
         ";
        $statement = $pdo->prepare($q);
        $statement->execute(array($user_title));
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    static function get_user_ext($user_id) {
        global $pdo;
        $statement = $pdo->prepare("SELECT ext from forum where id = ?");
        $statement->execute(array($user_id));
        return unserialize($statement->fetchColumn());
    }

    static function get_user_ext_lock($user_id) {
        /* The locking mechanism is in place to prevent a race condition data loss
            1. Thread 1 reads 'a'
            2. Thread 2 reads 'a'
            3. Thread 1 sets 'a:1'
            4. Thread 2 sets 'a,b:3'
            5. Thread 1's data was lost.
        If you are saving any ext data, you must call this method to get the current state first.
        If are only reading, you can use the other method..
        */
        global $pdo;
        if (!$GLOBALS['testing']) $pdo->beginTransaction();
        $statement = $pdo->prepare("SELECT ext from forum where id = ? FOR UPDATE");
        $statement->execute(array($user_id));
        return unserialize($statement->fetchColumn());
    }

    static function set_user_ext($user_id, $ext) {
        global $pdo;
        $statement = $pdo->prepare("UPDATE forum SET ext = ? where id = ?");
        $result = $statement->execute(array(serialize($ext), $user_id));
        if (!$GLOBALS['testing']) $pdo->commit();
        return $result;
    }

    static function new_user($user_title, $user_name, $user_email_hash) {
        global $pdo;
        $q = "
            INSERT INTO  `forum` (
                `title` ,
                `author_name` ,
                `author_email_hash` ,
                `type`
                )
            VALUES ('post', ?, ?, ?, ?, ?, 'user');
        ";

        $statement = $pdo->prepare($q);
        $statement->execute(array($user_title, $user_name, $user_email_hash));
        return $pdo->lastInsertId();
    }

    public static function hide_post($post_id, $recover=false)
    {
        global $pdo;
        if ($recover) $hide = 'N';
        else $hide = 'Y';
        $q = "
            UPDATE forum
            SET hidden = ?
            WHERE id = ?;
            ";
        $statement = $pdo->prepare($q);
        return $statement->execute(array($hide, $post_id));
    }
}