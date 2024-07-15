<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paystack;
use Redirect;
use App\Http\Controllers\PagesController;
use App\Deposit;
use App\User;
use App\Withdraw;
use App\DepositBonus;

class PaystackController extends Controller
{
    public function redirectToGateway($data)
    {
        try{
            return [
                'success' => true,
                'url' => Paystack::getAuthorizationResponse($data)['data']['authorization_url']
            ];
        }catch(\Exception $e) {
            dd($e);
            return Redirect::back()->withMessage(['msg'=>'The paystack token has expired. Please refresh the page and try again.', 'type'=>'error']);
        }
    }

    public function handleCallback(Request $r)
    {
        return redirect()->route('index')->with('success', 'Your Deposit successfully completed!');
        // $paymentDetails = Paystack::getPaymentData();

        //    dd($paymentDetails);
    }

    function checkPaystackIP($ip) {
        $list = ['52.31.139.75', '52.49.173.169', '52.214.14.220'];
        for($i = 0; $i < count($list); $i++) {
            if($list[$i] == $ip) return true;
        }
        return false;
    }

    public function webhook(Request $r)
    {
        // event name is charge.success not paymentrequest.success line 41
        //  removed orderby in line 47 and changed the operator in line 50
        // replaced line 61 with line 51, changed save to update.
        // changed line 75 causing error. changed $user to $this->user
        if(isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        if(!$this->checkPaystackIP($ip)) return ['msg' => 'Error check IP!', 'type' => 'error'];

        if($r->event === "charge.success") {
            $data = $r->data;
            $status = $data['status'];
            $amount = $data['amount'] / 100;
            $orderId = $data['metadata'];

            if($status == "success") {
                $deposit = Deposit::where('id', $orderId)->first();
                $deposit->amount = $amount;
                $deposit->status = 1;
                $deposit->update();

                $this->user = User::where('id', $deposit->user_id)->first();
                $this->user->balance += $deposit->amount;
                $this->user->update();

                $this->updateUserBonus($this->user, $amount);

                $this->redis->publish('updateBalance', json_encode([
                    'unique_id'    => $this->user->unique_id,
                    'balance' => round($this->user->balance, 2),
                    'bonus' => round($this->user->bonus, 2)
                ]));
                // $deposit->save();
            }
        }
        http_response_code(200);
        exit();
        // used $reason to replace $data->reason

        if($r->event === "transfer.success") {
            $data = $r->data;
            $reason = $data['reason'];
            $withdraw = Withdraw::where('id', $reason)->first();
            $withdraw->status = 1;
            $withdraw->save();
        }

        if($r->event === "transfer.failed") {
            $data = $r->data;
            $reason = $data['reason'];
            $withdraw = Withdraw::where('id', $reason)->first();
            $withdraw->status = -1;
            $withdraw->save();
        }
    }
    private function calcBonusAmtValue($bonusPercentage, $amount)
    {
        return ($bonusPercentage * $amount) / 100;
    }

    private function createDepositBonus($user, $depositBonusAmount)
    {
        return $user->depositBonuses()-> create([
            'deposit_bonus_amt' => $depositBonusAmount,
            'expires_at' => now()->addDays(30)
        ]);
    }

  /*  private function createDepositBonus($user, $depositBonusAmount)
    {
        return DepositBonus::create([
            'user_id' => $user->id,
            'deposit_bonus_amt' => $depositBonusAmount,
            'expires_at' => now()->addDays(30)
        ]);
    }
    */
    private function updateUserBonus($user, $userDepositedAmount)
    {
        if ($this->countUserSuccessfulDeposit($user->id) === 1)
        {
            if ($userDepositedAmount >= $this->settings->minFirstDepositValue)
            {
                // first deposit bonus
                if ($this->calcBonusAmtValue($this->settings->FirstDepositRate, $userDepositedAmount) > $this->settings->maxFirstDepositValue)
                {
                    $user->update([
                        'bonus' => $user->bonus += $this->settings->maxFirstDepositValue
                    ]);

                    $this->createDepositBonus($user, $this->settings->maxFirstDepositValue);
                }
                else {
                    $user->update([
                        'bonus' => $user->bonus += $this->calcBonusAmtValue($this->settings->FirstDepositRate, $userDepositedAmount)
                    ]);
                    $this->createDepositBonus($user, $this->calcBonusAmtValue($this->settings->FirstDepositRate, $userDepositedAmount));
                }
            }
        }

        if ($this->countUserSuccessfulDeposit($user->id) === 2)
        {
            // second deposit bonus
            if ($userDepositedAmount >= $this->settings->minSecondDepositValue)
            {
                if ($this->calcBonusAmtValue($this->settings->SecondDepositRate, $userDepositedAmount) > $this->settings->maxSecondDepositValue)
                {
                    $user->update([
                        'bonus' => $user->bonus += $this->settings->maxSecondDepositValue
                    ]);
                }
                else {
                    $user->update([
                        'bonus' => $user->bonus += $this->calcBonusAmtValue($this->settings->SecondDepositRate, $userDepositedAmount)
                    ]);
                }
            }
        }

        if ($this->countUserSuccessfulDeposit($user->id) === 3)
        {
            // third deposit bonus
            if ($userDepositedAmount >= $this->settings->minThirdDepositValue)
            {
                if ($this->calcBonusAmtValue($this->settings->ThirdDepositRate, $userDepositedAmount) > $this->settings->maxThirdDepositValue)
                {
                    $user->update([
                        'bonus' => $user->bonus += $this->settings->maxThirdDepositValue
                    ]);
                }
                else {
                    $user->update([
                        'bonus' => $user->bonus += $this->calcBonusAmtValue($this->settings->ThirdDepositRate, $userDepositedAmount)
                    ]);
                }
            }
        }

        if ($this->countUserSuccessfulDeposit($user->id) > 3)
        {
            // Regular deposit bonus
            if ($userDepositedAmount >= $this->settings->minRegDepositValue)
            {
                if ($this->calcBonusAmtValue($this->settings->RegDepositRate, $userDepositedAmount) > $this->settings->maxRegDepositValue)
                {
                    $user->update([
                        'bonus' => $user->bonus += $this->settings->maxRegDepositValue
                    ]);
                }
                else {
                    $user->update([
                        'bonus' => $user->bonus += $this->calcBonusAmtValue($this->settings->RegDepositRate, $userDepositedAmount)
                    ]);
                }
            }
        }
    }

    private function countUserSuccessfulDeposit($userId)
    {
        return DB::table('deposits')->where('user_id', '=', $userId)->where('status', '=', 1)->count();
    }

    public function bankResolve(Request $request)
    {
        return json_encode($this->callAPI('bank/resolve', [
            "account_number" => $request->account_number,
            "bank_code" => $request->bank_code,
        ]));
    }

    public function sendMoney($id)
    {
        $withdraw = Withdraw::where('id', $id)->first();

        if(!$withdraw) {
            return back()->with('error', 'Withdrawal not found');
        }
        if($withdraw->status != 0) {
            return back()->with('error', 'Payment already sent');
        }

        $bankResolveStatus = $this->callAPI('bank/resolve', [
            "account_number" => $withdraw->wallet,
            "bank_code" => $withdraw->bank_code,
        ])->status;

        if( $bankResolveStatus != true ) {
            return back()->with('error', $bankResolveStatus->message);
        }

        $recipient = $this->callAPI_POST('transferrecipient', [
            "type" => 'nuban',
            "name" => $withdraw->full_name,
            "account_number" => $withdraw->wallet,
            "bank_code" => $withdraw->bank_code,
            "currency" => "NGN",
        ]);
        if( $recipient->status != true ) {
            return back()->with('error', $recipient->message);
        }

        $withdraw->recipient_code = $recipient->data->recipient_code;
        $withdraw->save();

        $transfer = $this->callAPI_POST('transfer', [
            "source" => "balance",
            "amount" => $withdraw->value * 100,
            "recipient" => $withdraw->recipient_code,
            "reason" => $withdraw->id,
        ]);
        if( $transfer->status != true ) {
            return back()->with('error', $transfer->message);
            // dd('transfer error', $transfer->message);
        }

        $withdraw->status = 3;
        $withdraw->save();
        return back()->with('success', 'Sent!');
    }

    public function getBankList()
    {
        return json_decode($this->callAPI("bank", [
            "country" => "nigeria",
        ]))->data;
    }

    private function callAPI($uri, $queryParams)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/" . $uri . '?' . http_build_query($queryParams),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . env("PAYSTACK_SECRET_KEY"),
                "Cache-Control: no-cache",
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response);
    }

    private function callAPI_POST($uri, $queryParams)
    {
        $fields_string = http_build_query($queryParams);
        //open connection
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch,CURLOPT_URL, "https://api.paystack.co/" . $uri);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . env("PAYSTACK_SECRET_KEY"),
            "Cache-Control: no-cache",
        ));

        //So that curl_exec returns the contents of the cURL; rather than echoing it
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);

        //execute post
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result);
    }
}
