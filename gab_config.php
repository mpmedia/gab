<?php

class gab_settings {
    // Do not include the trailing slash on path names
    public $model_location = "gab_model.php";
    public $templates_folder = "templates";
    public $controller_folder = "controllers";
    public $extensions_folder = "extensions";

    // Ex. '' or '/gab'
    public $base_url = "";

    // Defines the actions allowed for each trust level.
    //   Number on left is action, right is minimum trust integer.
    // NOTE: The openid extension makes the first registered user a lvl 99 mod.
    public $trust_levels = array (
        "new_category" => 1,
        "delete" => 1,
        "see_deleted" => 1
    );
}