<?php

use Gismatul\SupportChat\SupportChat;

// Loaded inside the group configured in config('support-chat.routes.admin'): it must authenticate
// the user (default: web + auth). Each route additionally checks a supportChat.* gate.
SupportChat::adminRoutes();
