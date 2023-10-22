<?php

require __DIR__.'/API/SupportAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $supportApi;
    private $rest;
    
    function __construct() {
        parent::__construct('info.support');
        
        $this -> supportApi = new SupportAPI(
            $this -> log,
            $this -> amqp,
            SUPPORT_EMAIL
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> supportApi
            ]
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $th -> rest -> stop() -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>