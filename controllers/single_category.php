<?php

$this->assign("forum_section", "cat");
$this->assign("category", $matches[1]);
$this->assign("posts", forum::get_posts($matches[1]));