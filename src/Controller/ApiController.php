<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link      http://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Cake\Core\Configure;
use Cake\Network\Exception\ForbiddenException;
use Cake\Network\Exception\NotFoundException;
use Cake\View\Exception\MissingTemplateException;
use Cake\Network\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\Utility\Security;
use App\Controller\Component\EncryptionComponent;


class ApiController extends AppController
{

    public function initialize(){
        parent::initialize();
        $this->loadComponent('RequestHandler');
        $this->loadComponent('Encryption');
        $this->loadModel('Users');      
        if($this->request->params['action'] != 'login'){
            $this->validateToken();        
        }
    }

    public function beforeFilter(Event $event){
    }

    private function validateToken(){
        
        if(empty($this->request->getHeader('Authorization'))){

            $this->response->statusCode(401);
            $this->setAction('missingToken');

        }else{

            $token = $this->request->getHeader('Authorization')[0];
            
            $tokenArray = preg_split("/[.]/", $token);

            //Validates that the token has a dot
            if(sizeof($tokenArray) > 1){

                $partA = $tokenArray[0];
                $partB = $tokenArray[1];

                $email = EncryptionComponent::Decrypt($partA, Security::salt());
                $expDate = EncryptionComponent::Decrypt($partB, Security::salt());

                $user = $this->Users
                    ->find()
                    ->where(['email'=>$email,'active'=>1])
                    ->toArray()[0];

                if(!empty($user)){

                    $dateArray = preg_split("/[_]/",$expDate);
                    $datePart = $dateArray[0];
                    $timePart = $dateArray[1];
                    $timePart = str_replace('-', ':', $timePart);

                    $expirationDate = new Time($datePart . ' ' . $timePart);
                    $now = new Time(null, 'America/New_York');

                    if($now < $expirationDate){
                        $this->response->statusCode(401);
                        $this->setAction('expiredToken');
                    }

                }else{
                    $this->response->statusCode(401);
                    $this->setAction('invalidToken');
                }  

            }else{
                $this->response->statusCode(401);
                $this->setAction('invalidToken');
            }
        }
    }

    public function invalidToken(){
        $message = 'Invalid Authorization Token';
        $this->set('message',$message);
    }

    public function missingToken(){
        $message = 'Missing Authorization Token';
        $this->set('message',$message);
    }

    public function expiredToken(){
        $message = 'Expired Authorization Token';
        $this->set('message',$message);
    }

    /**
    * Generates a token based on the user's email and the expiration datetime of the session.
    * The session is set to last 4 hours.
    * @param string     $email Email address of the user
    * @return a token to validate the user's session
    */
    private function generateToken($email) {

        $expirationDate = new Time(null, 'America/New_York');
        $expirationDate->modify('+4 hours');
        $expirationDate = $expirationDate->format('Y-m-d_H-i-s');

        //The token is formed in 2 encrypted parts: email + token expiration Date
        $partA = EncryptionComponent::Crypt($email, Security::salt());
        $partB = EncryptionComponent::Crypt($expirationDate, Security::salt());
        return $partA . '.' . $partB;
    }

    /**
     * POST Authenticates the user and generates a new api token and stores it in the table.
     *
     * @return data of the user authenticated.
     * @throws \Exception.
     */
    public function login(){

        if($this->request->is('post')){

            try{

                $body = $this->request->getData();

                $user = $this->Users
                ->find()
                ->where(['email'=>$body['email'],'active'=>1])
                ->toArray()[0];
            
                //If the user is found by email, it verifies if the password matches the one stored in DB.
                if($user && password_verify($body['password'], $user['password'])){

                    $token = $this->generateToken($user['email']);
                    $this->set('user', $user);
                    $this->set('token', $token); 
                
                }else{
                
                    $this->response->statusCode(401);
                    $message = 'Unauthorized user';
                    $this->set('message',$message);
                }
            }catch (\Exception $e) {
                $this->response->statusCode(503);
                $this->set('error',$e->getMessage());
            }
        }else{
            $this->response->statusCode(405);
            $this->set('error','Method Not Allowed');
       }
    }    

    /**
     * POST Creates a new user with the provided data.
     *
     * @return data of the user added to the Database.
     * @throws \Exception.
     */
    public function add(){

        if($this->request->is('post')){

            try{

                $user = $this->Users->newEntity($this->request->getData());

                $user['password'] = password_hash($user['password'], PASSWORD_DEFAULT);
                
                if (!$this->Users->save($user)) {
                    $this->set('message','Error saving the User');
                }
                
                $this->set('user',$user);

            }catch (\Exception $e) {
                $this->response->statusCode(503);
                $this->set('error',$e->getMessage());
            }

        }else{
           $this->response->statusCode(405);
           $this->set('error','Method Not Allowed');
       }
    }

    /**
     * GET Reads the list of users allowing pagination, sorting and limit the data returned.
     *
     * @param integer   $limit The limit of rows to be retrieved.
     * @param string    $sortField The field to be sorted by.
     * @param string    $sortDirection Possible values are 'asc' or 'desc'.
     * @param integer   page This is the page number that implements the Paginator automatically. 
     * @return list of users.
     * @throws \Exception.
     */
    public function index(){

       if($this->request->is(['get'])){

           try {

                if(isset($this->request->query['limit'])){
                    $limit = $this->request->query['limit'];
                }
                
                if(isset($this->request->query['sortField'])){
                    $sortField = $this->request->query['sortField'];
                }
                
                if(isset($this->request->query['sortDirection'])){
                    $sortDirection = $this->request->query['sortDirection'];
                }

                if(isset($limit) && $limit>0){
                    $this->paginate['limit'] = $limit;            
                }

                if(isset($sortField) && isset($sortDirection) && 
                    (strtolower($sortDirection) === 'asc' || strtolower($sortDirection) === 'desc')){
                    $this->paginate['order'] = ['Users.'.$sortField => $sortDirection];
                }

                //Search Fields
                if(isset($this->request->query['first_name'])){
                    $first_name = $this->request->query['first_name'];
                    $conditions['first_name LIKE'] = '%'. $first_name .'%';
                }

                if(isset($this->request->query['last_name'])){
                    $last_name = $this->request->query['last_name'];
                    $conditions['last_name LIKE'] = '%'. $last_name .'%';
                }

                if(isset($this->request->query['ci_number'])){
                    $ci_number = $this->request->query['ci_number'];
                    $conditions['ci_number LIKE '] = '%'.$ci_number.'%';
                }

                if(isset($this->request->query['email'])){
                    $email = $this->request->query['email'];
                    $conditions['email LIKE'] = '%'. $email .'%';
                }

                $conditions['active'] = 1;

                $this->paginate['conditions'] = $conditions;

                $query = $this->Users
                        ->find()
                        ->select(['user_id','first_name','last_name','ci_number','email','active']);
                $users = $this->paginate($query);

                $this->set('users',$users);

            } catch (\Exception $e) {
                $this->response->statusCode(503);
                $this->set('error',$e->getMessage());
            }

       }else{
            $this->response->statusCode(405);
            $this->set('error','Method Not Allowed');
       }
    }

    /**
     * GET Retrieves a user by the given user id.
     *
     * @return user's data.
     * @throws \Exception.
     */
    public function getUser($id){

       if($this->request->is('get')){

          try{

                $user = $this->Users
                        ->find()
                        ->where(['user_id'=>$id])
                        ->select(['user_id','first_name','last_name','ci_number','email','active'])
                        ->toArray();

                if($user){
                    $this->set('user',$user[0]);
                }else{
                    throw new NotFoundException("User Not Found", 1);
                }

            }catch (\Exception $e) {
                $this->response->statusCode(404);
                $this->set('error',$e->getMessage());
            }

       }else{
           $this->response->statusCode(405);
           $this->set('error','Method Not Allowed');
       }
   }    

    /**
     * PUT Updates a given user with the provided data.
     *
     * @return user's data updated from the given object.
     * @throws \Exception.
     */
    public function edit($id){

       if($this->request->is('put')){

          try{

                $user = $this->Users->find()->where(['user_id'=>$id])->toArray();

                if($user){
                    $user = $user[0];

                    $body = $this->request->getData();

                    if(!empty($body['password'])){
                        $user['password'] = password_hash($body['password'], PASSWORD_DEFAULT);
                    }

                    $user = $this->Users->patchEntity($user, $body);

                    if (!$this->Users->save($user)) {
                        $this->set('message','Error updating the User');
                    }

                    $this->set('user',$user);

                }else{
                    throw new NotFoundException("User Not Found", 1);
                }

            }catch (\Exception $e) {
                $this->response->statusCode(404);
                $this->set('error',$e->getMessage());
            }

       }else{
           $this->response->statusCode(405);
           $this->set('error','Method Not Allowed');
       }
   }

    /**
     * DELETE Removes a given user.
     *
     * @return user's data updated from the given object.
     * @throws \Exception.
     */
    public function delete($id){

       if($this->request->is('delete')){

          try{

                $user = $this->Users->get($id);

                if($user){

                    if ($this->Users->delete($user)) {
                        $message = 'User deleted succesfully';
                    }else{
                        $message = 'User could not be deleted';
                    }

                    $this->set('message',$message);

                }

            }catch (\Exception $e) {
                $this->response->statusCode(404);
                $this->set('error',$e->getMessage());
            }

       }else{
           $this->response->statusCode(405);
           $this->set('error','Method Not Allowed');
       }
   }


}