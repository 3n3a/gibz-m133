<?php

namespace M133;

include_once __DIR__ . '/config.php';

use M133\Page as Page;
use M133\Validate as Validate;

class IndexPage extends Page {

    function __construct(
        private Template $template,
        private Database $database,
        private $config
    ) {
        $this->checkInstalled(__DIR__ . '/.setupdone');
        $this->initRoutes();
        $this->sendPage();
    }

    public function sendPage() {
        if ($this->isAuthenticated()) {
            
            
            $username = $this->getSessionValueIfExists('username');
            $email = $this->config->controllers['user']->getUser( $username, ["email"] )['email'];
            $this->template->renderIntoBase(
                [
                    'title' => 'Home',
                    'app_content' => 'index.html',
                    'username' => $username ?? "User",
                    'full_name' => $username ?? "User",
                    'email' => $email,
                    'ranking_table' => 'components/ranking_table.html'
                ],
                $this->config->menus
            );

        } else {
            
            header('Location: /login.php');

        }
    }
}



// Instantiate new App with Router...
$index = new IndexPage(
    $config->template,
    $config->db,
    $config
);