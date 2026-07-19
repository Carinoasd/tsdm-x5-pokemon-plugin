<?php

/**
 * game.inc.php - Pokemon Game Module Entry Point
 * 
 * Discuz plugin routing: plugin.php?id=pokemon:game
 * 将请求转发到 pokemon_system/game.php
 */
defined('IN_DISCUZ') || exit('Access Denied');

// 将请求路由到实际的 game.php
include_once DISCUZ_ROOT . './source/plugin/pokemon/pokemon_system/game.php';
