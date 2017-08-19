<?php
/*
 * Stuff to handle Discoin transactions
 * 
 * @author MacDue
 */
 
namespace Discoin\Transactions;

require_once __DIR__."/../scripts/dbconn.php";
require_once __DIR__."/../scripts/util.php";
require_once __DIR__."/../scripts/discordstuff.php";
require_once __DIR__."/discoin.php";
require_once __DIR__."/bots.php";
require_once __DIR__."/users.php";


use function \MacDue\Util\send_json as send_json;
use function \MacDue\Util\send_json_error as send_json_error;
use function \MacDue\Util\format_timestamp as format_timestamp;


// A webhook for new transaction alerts.
define("TRANSACTIONS_WEBHOOK", "https://discordapp.com/api/webhooks/348178790863732737/geIjoZrNTzqFrN4Xw2K89cQYgDScWqT3nVUO2y7C61QadJBmCQFVXWYf2ctcJX21LKqb");


/*
 * A Discoin transaction
 * 
 * @param \Discoin\Users\User $user A user that is the sender
 * @param string $source The souce bot currency
 * @param string $target $user The target bot currency
 * @param float $amount The amount the transaction (in the source currency)
 * @param string $type Transaction type ("normal" or "refund")
 *  
 * @author MacDue
 */
class Transaction extends \Discoin\Object implements \JsonSerializable
{
    public $user;
    public $timestamp;
    public $source;
    public $target;
    public $amount;
    public $receipt;
    public $type;
    public $processed = False;
    public $process_time = 0;
    
    
    function __construct($user, $source, $target, $amount, $type="normal")
    {
        $this->user = $user->id;
        $this->source = $source;
        $this->target = $target;
        $this->type = $type;
        
        if ($amount <= 0)
        {
            send_json_error("invalid amount");
        }
        
        $source_bot = \Discoin\Bots\get_bot(["currency_code" => $source]);
        $target_bot = \Discoin\Bots\get_bot(["currency_code" => $target]);
        
        if (is_null($target_bot))
        {
            send_json_error("invalid destination currency");
        }
        
        $this->amount_source = $amount;
        $this->amount_target = $amount * $target_bot->from_discoin;
        $this->amount_discoin = $amount * $source_bot->to_discoin;
        
        if ($user->exceeds_daily_limit($source_bot, $target_bot, $this->amount_discoin))
        {
            Transaction::decline("per-user limit exceeded", $target_bot->limit_user);
        } else if ($user->exceeds_global_limit($source_bot, $target_bot, $this->amount_discoin)) 
        {
            Transaction::decline("total limit exceeded", $target_bot->limit_global);
        }
                
        // If we get here we're okay!
        $this->timestamp = time();
        $this->receipt = $this->get_receipt();
        $user->log_transaction($this);
        $this->approve($target_bot->limit_user - $user->daily_exchanges[$target]);
        $this->save();
        
        // Send a nice little webhook!
        $this->new_transaction_webhook();
    }
    
    private static function decline($reason, $limit=null)
    {
        http_response_code(400);
        $declined = ["status" => "declined", "reason" => $reason];
        if (!is_null($limit))
            $declined["limit"] = $limit;
        send_json($declined, 400);
        die();
    }
    
    private function approve($limit_now)
    {
        send_json(["status" => "approved",
                   "receipt" => $this->receipt,
                   "limitNow" => $limit_now,
                   "resultAmount" => $this->amount_target]);
    }
    
    private function new_transaction_webhook()
    {
        // Makes a nice little embed for the transaction
        $transaction_embed = new \Discord\Embed($title=":new: New transaction!", $colour=7506394);
        $transaction_embed->add_field($name="User", $value=$this->user, $inline=True);
        $transaction_embed->add_field($name="Exchange", 
                                      $value="$this->amount_source $this->source => $this->amount_target $this->target", 
                                      $inline=True);
        $transaction_embed->add_field($name="Receipt", $value=$this->receipt);
        $transaction_embed->set_footer($text="Sent ".format_timestamp($this->timestamp));
        
        \Discord\send_webhook(TRANSACTIONS_WEBHOOK, ["embeds" => [$transaction_embed]]);
    }
    
    private function get_receipt()
    {
        return sha1(uniqid(time().$this->user, True));
    }
    
    /*
     * A factory? for making transactions.
     * 
     * @param \Discoin\Bots\Bot $source_bot The source bot
     * @param stdClass $transaction_info The parsed JSON transaction info (see API docs).
     * 
     * returns Transaction The Discoin transaction
     */
    public static function create_transaction($source_bot, $transaction_info)
    {
        if (isset($transaction_info->user, $transaction_info->amount, $transaction_info->exchangeTo))
        {
            $user = \Discoin\Users\get_user($transaction_info->user);
            if (is_null($user))
            {
                Transaction::decline("verify required");
            } else if (!is_numeric($transaction_info->amount)) 
            {
                Transaction::decline("amount NaN");
            }
            $amount = floatval($transaction_info->amount);
            
            $transaction = new Transaction($user, $source_bot->currency_code, strtoupper($transaction_info->exchangeTo), $amount);
            return $transaction;
        } 
        else
        {
            send_json_error("bad post");
        }
        return null;
    }
    
    // JSON for GET /transactions
    public function jsonSerialize() {
        
          return ["user" => $this->user,
                  "timestamp" => $this->timestamp,
                  "source" => $this->source,
                  "amount" => $this->amount_target,
                  "receipt" => $this->receipt];
    }
    
    public function __toString(){
        
        if ($this->processed)
            $processed = format_timestamp($this->process_time);
        else
            $processed = "UNPROCESSED        ";
        // Seg fault if you make a typo /r/lolphp
        return "||$this->receipt|| "
                .format_timestamp($this->timestamp)
                ." || $processed || $this->source  || $this->target  || $this->amount_discoin";
    }
    
    public function save()
    {
        \MacDue\DB\upsert("transactions", $this->receipt, $this);
    }
    
}

?>
