<?php
use Medoo\Medoo;
/**
 * @author      => Alireza jarayedi / @iamAlira / @Alirea
 * @version     => 2
 * @copyright   => Copyright (c) 2022, ©️Nova Code
 * @link        => https://nova-code.ir
 * @internal    => ©️Nova Code
 */
#-------------------------------------> Send Request Telegram <-------------------------------------#

class sisoog  {

    /**
     * Summary of update
     * @var
     */
    public $update;
    /**
     * Summary of Db
     * @var Medoo
     */
    public $Db;
    public $text;
    public $strings;
    public $UserId;

    public array $DATAUSER = [];
    /**
     * Summary of lastResult
     * @var
     */
    public $lastResult;
    /**
     * Summary of __construct
     * @param string $token
     * @param mixed  $Admins
     * @param mixed  $report
     */
    public function __construct(
        public string $token,
        public $Admins,
        public $report,
    ){ }
    #--------------------------> Database  <--------------------------#
    public function SetDb($Db)
    {
        $this->Db = $Db;
    }
    public function step($step = null , $chat_id = null)
    {
        $this->Db->update('account' , [
            'step' => $step
        ] , ['chat_id' => $this->handleChatId($chat_id , $this->UserId) ]);
    }
    public function getStep($chat_id = null) 
    {
        return $this->Db->get('account' , ['step'] , [
            'chat_id' => $this->handleChatId($chat_id , $this->UserId) 
        ])['step'];
    }

    public function DBError(PDOException $error ,callable $callback)
    {
        $error = print_r('ERROR DATABASE:' . PHP_EOL . $error, true);
        $this->report($error);
        // $this->send_message($error , $this->Admins[0]);
        if(is_callable($callback))
            $callback($error);
    }
    #--------------------------> Database end functions <--------------------------#

    #-----------------> Control variable  <-----------------#

    #-----------------> Control Strings And keyboard <-----------------#
    public function setStrings($strings)
    {
        $this->strings = $strings;
    }
    public function getStrings($key = false)
    {
        if($key){
            if(isset($this->strings[$key]))
                return $this->strings[$key];
        }else
            return $this->strings;

        return 'ttt';
    }
    #-----------------> post <-----------------#
    public function post($url , array $data)
    {
        $request = curl_init($url);
        curl_setopt_array($request , [
            CURLOPT_RETURNTRANSFER    => true, 
            CURLOPT_POSTFIELDS        => $data, 
            CURLOPT_IPRESOLVE         => CURL_IPRESOLVE_V4,
            // مهم: تعیین timeout تا در صورت کندی/قطعیِ API تلگرام، ورکر PHP
            // بی‌نهایت بلاک نشود و کل سرور از دسترس خارج نشود.
            CURLOPT_CONNECTTIMEOUT    => 5,
            CURLOPT_TIMEOUT           => 12,
            CURLOPT_NOSIGNAL          => 1,
            ]);
        $result = curl_exec($request);
        if(curl_errno($request)) {
            $err = curl_error($request);
            curl_close($request);
            // برای جلوگیری از حلقه‌ی بازگشتی (report → send_message → post)،
            // خطا را فقط در لاگ سرور ثبت می‌کنیم، نه از طریق تلگرام.
            @error_log('MainBot Telegram request failed: ' . $err);
            return false;
        }else {
            curl_close($request);
            $result = json_decode($result , true);
            return  $result;
        }
    }

    #-----------------> request telegram <-----------------#
    public function handleChatId(...$parameters)
    {
        foreach($parameters as $parameter)
        {
            if(!is_null($parameter))
                return $parameter;
        }
        // $this->report('a not not not not a chatId!! ' . PHP_EOL . json_encode($_SERVER) . PHP_EOL . json_encode($_REQUEST));
        return '404';
    }
    public function requtel(string $method , array $data = [] , $convention = curl)
    {
        if($convention == 'curl')
        {
            $result =  $this->post("https://api.telegram.org/bot{$this->token}/$method" , $data);
            $result = $result['result'] ?? $result;
            $this->lastResult = $result;
            return $result;
        }else 
        {
            return $this->webhook_answer($method , $data);
        }
    }
    public function webhook_answer(string $method,array $parameters) {
        
        $parameters["method"] = $method;
        echo json_encode($parameters);
        
        return true;
    }
    #-----------------> request telegram functions end <-----------------#

    #-----------------> Admin <-----------------#
    public function set_Admin($Admins) 
    {
        $this->Admins = $Admins;
    }
    /**
     * Summary of Admin
     * @return array
     */
    public function Admin():array
    {
        return $this->Admins;
    }
    /**
     * Summary of isAdmin
     * @param array $chatId
     * @return bool
     */
    public function isAdmin(...$chatId) :bool
    {
        foreach($chatId as $user)
        {
            if(in_array($user , $this->Admins))
                return true;
        }
        return false;
    }


    public function report($data = 'test', $chatId = report)
    {
        if(is_array($data))
            $data = 'array:'. PHP_EOL .  json_encode($data , JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        elseif(is_object($data))
            $data = 'Object:'. PHP_EOL .  json_encode($data , JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $this->send_message($data , $chatId);
    }
    #-----------------> Admin end <-----------------#

    #-----------------> Handle Text <-----------------#
    /**
     * Summary of handleSwitch
     * @param mixed $handle
     * @param string $text
     * @param callable $if_not
     * @return void
     */
    public function handleSwitch(string $text , mixed $handle , callable|null $if_not = null , $start = null , $finish = null)
    {
        if(isset($start))
            $start($this);
        if(isset($handle[$text]))
        {
            $return = $handle[$text]($this , $text);
        }else {
            if(!is_null($if_not))
            $return = $if_not($this , $text);
        }
        if(!is_null($return))
            $this->report($return);


        if(isset($finish))
            $finish($this);
    }
    public function isJson($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    #-----------------> get type <-----------------#
    /**
     * Summary of type
     * @return string
     */
    public function type():string
    {
        if (isset($this->update->message->text)) {
            $ret =  'text';
        } elseif (isset($this->update->message->photo)) {
            $ret = 'photo';
        } elseif (isset($this->update->message->video)) {
            $ret = 'video';
        } elseif (isset($this->update->message->audio)) {
            $ret = 'audio';
        } elseif (isset($this->update->message->voice)) {
            $ret = 'voice';
        } elseif (isset($this->update->message->document)) {
            $ret = 'document';
        } elseif (isset($this->update->message->sticker)) {
            $ret = 'sticker';
        }elseif (isset($this->update->message->forward_date)) {
            $ret = 'forward';
        }else {
            $ret = 'text';
        }
        return $ret;
    }
   
   #-----------------> send photo telegram <-----------------#
public function send_photo($photo, $caption = null, $chat_id = null, $kboard = null, $other = [], $send = 'curl', $parse_mode = 'HTML')
{
    $chat_id = $this->handleChatId($chat_id, $this->UserId);

    $data = [
        'chat_id' => $chat_id,
        'photo' => $photo
    ];

    if (isset($caption)) {
        $data['caption'] = $caption;
    }

    if (isset($parse_mode)) {
        $data['parse_mode'] = $parse_mode;
    }

    if (is_string($kboard)) {
        $kboard = $this->getStrings($kboard);
    }

    if (isset($kboard)) {
        $data['reply_markup'] = json_encode($kboard);
    }

    $data = array_merge($data, $other);

    // ارسال عکس
    return $this->requtel('sendPhoto', $data, $send);
}
   
   
   
   
   
   
   
   
   
   
   
   
   
   
    #-----------------> send message telegram <-----------------#
 public function send_message($message ,$chat_id = null , $kboard = null , $other = [] , $new_step = 'null' , $dataUser = 'null' ,$send = curl , $debug = false , $parse_mode = null , $link_preview_options = null)
    {
        // $this->requtel('sendChatAction' , [
        //     'chat_id' => $chat_id ?? $this->update->message->chat->id,
        //     'action'  => 'typing'
        // ]);
        $chat_id = $this->handleChatId($chat_id , $this->UserId);
        $data = [
            'text'    => $message,
            'chat_id' => $chat_id
        ];
        if(is_string($kboard)) $kboard = $this->getStrings($kboard);
        if(isset($kboard))              $data['reply_markup']             = json_encode($kboard);
        if(isset($parse_mode))              $data['parse_mode']             = $parse_mode;
        if(isset($link_preview_options))   $data['link_preview_options'] = json_encode( $link_preview_options );
        $data = array_merge($data , $other);

        if($new_step !== 'null') $this->step($new_step , $chat_id);
        if($dataUser !== 'null') $this->Db->update('account' , ['data' => $dataUser] , ['chat_id' => $chat_id]);

        if($debug) $this->report($data);
        return $this->requtel('sendMessage' , $data , $send);
    }
    #-----------------> send with markdown message telegram <-----------------#
public function sendMessageWithMarkdown($message, $chatId = null, $keyboard = null, $other = [], $newStep = null, $dataUser = null, $send = 'curl', $debug = false, $link_preview_options = null)
{
    // Handle chat ID
    $chatId = $this->handleChatId($chatId, $this->UserId);

    // Prepare the data array
    $data = [
        'text'    => $message,
        'chat_id' => $chatId,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];

    // Process the keyboard if provided
    if (is_string($keyboard)) {
        $keyboard = $this->getStrings($keyboard);
    }

    if (isset($keyboard)) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    // Merge additional parameters
    $data = array_merge($data, $other);

    // Handle the new step if provided
    if ($newStep !== null) {
        $this->step($newStep, $chatId);
    }

    // Update the user data if provided
    if ($dataUser !== null) {
        $this->Db->update('account', ['data' => $dataUser], ['chat_id' => $chatId]);
    }

    // Report data if debugging
    if ($debug) {
        $this->report($data);
    }

    // Send the message
    return $this->requtel('sendMessage', $data, $send);
}    
    
    
    
    
    #-----------------> delete message telegram <-----------------#
    public function delete_message( $message_id = null ,$chat_id = null , $send = curl) 
    {
        return $this->requtel('deleteMessage' , [
            'chat_id'    => $this->handleChatId($chat_id , $this->UserId),
            'message_id' => $this->handleChatId($message_id , $this->update->callback_query->message->message_id ,  $this->update->message->message_id)
        ] , $send);
    }
    #-----------------> edit Message telegram <-----------------#
    public function edit_Message($text , $kboard = null  , $chat_id = null , $message_id = null , $data = null , $send = curl , $new_step = 'null')  
    {
        $data = [
            'text'       => $text,
            'chat_id'    => $this->handleChatId($chat_id , $this->UserId),
            'message_id' => $this->handleChatId($message_id , $this->update->callback_query->message->message_id)
        ];
        if(is_string($kboard))          $kboard                           = $this->getStrings($kboard);
        if(isset($kboard))              $data['reply_markup']             = json_encode($kboard);

        if($new_step !== 'null') $this->step($new_step , $chat_id);

        return $this->requtel('editMessageText' , $data , $send);
    }
    
    
    
    #-----------------> edit Caption telegram <-----------------#
    public function editMessageCaption($caption , $chat_id = null , $message_id , $data = [] , $send = curl)  
    {
        $data = [
            'caption'       => $caption,
            'chat_id'       => $this->handleChatId($chat_id , $this->UserId),
            'message_id'    => $message_id
        ];
 
        return $this->requtel('editMessageCaption' , $data , $send);
    }
        #-----------------> edit Caption telegram <-----------------#
        public function editMessageReplyMarkup($reply_markup  , $chat_id = null , $message_id =null , $send = curl)  
        {
            $data = [
                'chat_id'       => $this->handleChatId($chat_id , $this->UserId),
                'message_id'    => $this->handleChatId($message_id , $this->update->callback_query->message->message_id),
                'reply_markup'  => json_encode((is_string($reply_markup) ? $this->getStrings($reply_markup) : $reply_markup))
            ];
                
            return $this->requtel('editMessageReplyMarkup' , $data , $send);
        }
    #-----------------> left chat telegram <-----------------#
    public function left_chat($chat_id = null , $send = curl) {
        return $this->requtel('leaveChat' , [
            'chat_id' => $this->handleChatId($chat_id , $this->UserId)
        ] , $send );
    }
    #-----------------> copy message  <-----------------#

    public function copyMessage($chat_id = null , $from_chat_id , $message_id ,$reply_markup = null, $other = [],  $send = curl ) {
        $data = [
            'chat_id'       => $from_chat_id, 
            'from_chat_id'  => $this->handleChatId($chat_id , $this->UserId),
            'message_id'    => $message_id
        ];
        if(isset($reply_markup)) $data['reply_markup'] = json_encode($reply_markup);
        $data = array_merge($data , $other);

        return $this->requtel('copyMessage' , $data , $send);
    }
    #-----------------> forward Message  <-----------------#
    public function forwardMessage($chat_id = null , $from_chat_id , $message_id ,$reply_markup = null , $other = [],  $send = curl ) {
        $data = [
            'chat_id'       => $chat_id, 
            'from_chat_id'  => $this->handleChatId($from_chat_id , $this->UserId ),
            'message_id'    => $message_id
        ];
        
        if(isset($reply_markup)) $data['reply_markup'] = json_encode($reply_markup);

        $data = array_merge($data , $other);
        return $this->requtel('forwardMessage' , $data , $send);
    }


    public function getChatMember($chat_id = null , $user_id = null  )
    {
        $data = [
            'chat_id' => $chat_id,
            'user_id' => $this->handleChatId($user_id , $this->UserId),
        ];
        return $this->requtel('getChatMember' , $data);
    }


    public function setJson($key , $valu , $data = null) 
    {
        if(!isset($data)) $data = $this->Db->get('account' , 'data' , ['chat_id' => $this->UserId]);
        $data = json_decode($data,true);
        $data[$key] = $valu;
        return json_encode($data);
    }


    public function convert($string) {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٩', '٨', '٧', '٦', '٥', '٤', '٣', '٢', '١','٠'];
         $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $num = range(0, 9);
        $convertedPersianNums = str_replace($persian, $num, $string);
        $englishNumbersOnly = str_replace($arabic, $num, $convertedPersianNums);
        return $englishNumbersOnly;
    }

    public function rand_string( $length ) {
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    return substr(str_shuffle($chars),0,$length);
    } 

    public function is_num($text = null)
    {
        if(!isset($text)) return is_numeric($this->text);
        else return is_numeric($text);
    }

    public function GetinfoUser($account = null , $user_id = null) 
    {
        if(!isset($account)) $account = $this->Db->get('account' , ['mozayede_ok' , 'mozayede_Cancell'] , ['chat_id' => $this->handleChatId($user_id , $this->UserId) ]);
        return "⤵️ تاریخچه تبادلات کاربر \n 🟢 موفق: {$account['mozayede_ok']} | 🔴 ناموفق: {$account['mozayede_Cancell']}";
    }

}


?>


