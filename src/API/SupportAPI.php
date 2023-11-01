<?php

use Infinex\Exceptions\Error;
use function Infinex\Validation\validateEmail;
use React\Promise;

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
            'wallet.wallet',
            'getAsset',
            [ 'assetid' => @$body['assetid'] ]
        ) -> then(function($asset) use($th, $auth, $body) {
            return $th -> amqp -> call(
                'wallet.io',
                'getAnPair',
                [
                    'assetid' => $asset['assetid'],
                    'netid' => @$body['netid']
                ]
            ) -> then(function($an) use($th, $auth, $body, $asset) {
                return $th -> amqp -> call(
                    'account.account',
                    'getUser',
                    [ 'uid' => $auth['uid'] ]
                ) -> then(function($user) use($th, $body, $asset, $an) {
                    $text = 'E-mail (verified): '.$user['email'].'<br>'
                          . 'Asset: '.$asset['name'].' ('.$asset['assetid'].')<br>'
                          . 'Network: '.$an['network']['name'].'<br>'
                          . 'Txid: '.$body['txid'].'<br>'
                          . 'Description: '.$body['description'];
                
                    $th -> sendMail(
                        'Deposit '.$asset['assetid'].' ('.$an['network']['name'].')',
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
            'wallet.io',
            'getTransaction',
            [ 'xid' => @$body['xid'] ]
        ) -> then(function($tx) use($th, $auth, $body) {
            if($tx['uid'] != $auth['uid'])
                throw new Error('FORBIDDEN', 'No permission to transaction '.$tx['xid'], 403);
            
            if($tx['type'] != 'WITHDRAWAL')
                throw new Error('INVALID_TRANSACTION_TYPE', 'Transaction '.$tx['xid'].' is not a withdrawal', 405);
            
            if(!in_array($tx['status'], [
                'CONFIRM_PENDING',
                'DONE',
                'CANCEL_PENDING'
            ]))
                throw new Error('INVALID_TRANSACTION_STATUS', 'Transaction status not allowed', 405);
            
            if($tx['createTime'] > time() - (8 * 60 * 60))
                throw new Error('TOO_EARLY', 'Less than 8 hours have passed since the withdrawal order', 406);
        
            $promises = [];
            
            $promises[] = $th -> amqp -> call(
                'wallet.wallet',
                'getAsset',
                [ 'assetid' => $tx['assetid'] ]
            );
            
            $promises[] = $th -> amqp -> call(
                'wallet.io',
                'getNetwork',
                [ 'netid' => $tx['netid'] ]
            );
            
            $promises[] = $th -> amqp -> call(
                'account.account',
                'getUser',
                [ 'uid' => $auth['uid'] ]
            );
            
            return Promise\all($promises) -> then(function($data) use($th, $body, $tx) {
                $asset = $data[0];
                $network = $data[1];
                $user = $data[2];
                
                $text = 'E-mail (verified): '.$user['email'].'<br>'
                      . 'Asset: '.$asset['name'].' ('.$asset['assetid'].')<br>'
                      . 'Network: '.$network['name'].'<br>'
                      . 'Address: '.$tx['address'].'<br>'
                      . 'Memo: '.($tx['memo'] ? $tx['memo'] : '-').'<br>'
                      . 'Xid: '.$tx['xid'].'<br>'
                      . 'Txid: '.($tx['txid'] ? $tx['txid'] : '-').'<br>'
                      . 'Description: '.$body['description'];
            
                $th -> sendMail(
                    'Withdrawal '.$asset['assetid'].' ('.$network['name'].')',
                    $text
                );
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