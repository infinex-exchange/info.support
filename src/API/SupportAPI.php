<?php

use Infinex\Exceptions\Error;
use function Infinex\Validation\validateEmail;

class SupportAPI {
    private $log;
    private $amqp;
    private $email;
    
    function __construct($log, $amqp, $email) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> email = $email;
        
        $this -> log -> debug('Initialized support API');
    }
    
    public function initRoutes($rc) {
        $rc -> post('/login', [$this, 'topicLogin']);
        $rc -> post('/other', [$this, 'topicOther']);
        $rc -> post('/deposit', [$this, 'topicDeposit']);
        $rc -> post('/withdrawal', [$this, 'topicWithdrawal']);
    }
    
    public function topicLogin($path, $query, $body, $auth) {
        if($auth)
            throw new Error('ALREADY_LOGGED_IN', 'Already logged in', 403);
        
        if(!isset($body['email']))
            throw new Error('MISSING_DATA', 'email', 400);
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        
        if(!validateEmail($body['email']))
            throw new Error('VALIDATION_ERROR', 'email', 400);
        if(!is_string($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
        
        $text = 'E-mail (provided by user): '.$body['email'].'<br>'
              . 'Description: '.$body['description'];
        
        $this -> sendMail('Login or registration', $text);
    }
    
    public function topicOther($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        
        if(!is_string($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
        
        return $this -> amqp -> call(
            'account.account',
            'getUser',
            [ 'uid' => $auth['uid'] ]
        ) -> then(function($user) use($th, $body) {
            $text = 'E-mail (verified): '.$user['email'].'<br>'
                  . 'Description: '.$body['description'];
        
            $th -> sendMail('Other issues', $text);
        });
    }
    
    public function topicDeposit($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        if(!isset($body['txid']))
            throw new Error('MISSING_DATA', 'txid', 400);
        
        if(!is_string($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
        if(!is_string($body['txid']) || strlen($body['txid']) > 128)
            throw new Error('VALIDATION_ERROR', 'txid', 400);
        
        return $this -> amqp -> call(
            'account.account',
            'getUser',
            [ 'uid' => $auth['uid'] ]
        ) -> then(function($user) use($th, $body) {
            return $th -> amqp -> call(
                'wallet.wallet',
                'getAsset',
                [ 'symbol' => @$body['asset'] ]
            ) -> then(function($asset) use($th, $body, $user) {
                return $th -> amqp -> call(
                    'wallet.io',
                    'getAnPair',
                    [
                        'assetid' => $asset['assetid'],
                        'networkSymbol' => @$body['network']
                    ]
                ) -> then(function($an) use($th, $body, $user, $asset) {
                    $text = 'E-mail (verified): '.$user['email'].'<br>'
                          . 'Asset: '.$asset['name'].' ('.$asset['symbol'].')<br>'
                          . 'Network: '.$an['network']['name'].'<br>'
                          . 'Txid: '.$body['txid'].'<br>'
                          . 'Description: '.$body['description'];
                
                    $th -> sendMail(
                        'Deposit '.$asset['symbol'].' ('.$an['network']['name'].')',
                        $text
                    );
                });
            });
        });
    }
    
    public function topicWithdrawal($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        
        if(!is_string($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
        
        return $this -> amqp -> call(
            'account.account',
            'getUser',
            [ 'uid' => $auth['uid'] ]
        ) -> then(function($user) use($th, $body) {
            return $th -> amqp -> call(
                'wallet.wallet',
                'getAsset',
                [ 'symbol' => @$body['asset'] ]
            ) -> then(function($asset) use($th, $body, $user) {
                return $th -> amqp -> call(
                    'wallet.io',
                    'getAnPair',
                    [
                        'assetid' => $asset['assetid'],
                        'networkSymbol' => @$body['network']
                    ]
                ) -> then(function($an) use($th, $body, $user, $asset) {
                    $text = 'E-mail (verified): '.$user['email'].'<br>'
                          . 'Asset: '.$asset['name'].' ('.$asset['symbol'].')<br>'
                          . 'Network: '.$an['network']['name'].'<br>'
                          . 'Txid: '.$body['txid'].'<br>'
                          . 'Description: '.$body['description'];
                
                    $th -> sendMail(
                        'Deposit '.$asset['symbol'].' ('.$an['network']['name'].')',
                        $text
                    );
                });
            });
        });
    }
    
    private function sendMail($subject, $text) {
        $this -> amqp -> pub(
            'mail',
            [
                'template' => 'admin_support_request',
                'context' => [
                    'subject' => $subject,
                    'form_body' => $text
                ],
                'email' => $this -> email
            ]
        );
    }
}

?>