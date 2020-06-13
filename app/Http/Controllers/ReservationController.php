<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Reservation;
use App\Models\Hotel;
use App\Models\Room;
use Auth;
use App\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Stripe;
use App\Mail\MyTestMail;
use Carbon\Carbon;
use Illuminate\Support\Str;
class ReservationController extends Controller
{
    /**
     * Display a listing of the reservations.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $users = User::get();
        if (1 == Auth::user()->id) { //if Admin show all reservations
            $reservations = Reservation::with('room', 'room.hotel')
                    ->orderBy('arrival', 'asc')
                    ->paginate(15);
                    
        }else{ // if normal user show only his reservations
                $reservations = Reservation::with('room', 'room.hotel')
                    ->where('user_id', Auth::user()->id)
                    ->orderBy('arrival', 'asc')
                    ->get();
             }
        return view('dashboard.reservations', compact('reservations','users'));
    }

    /**
     * Show the form for creating a new reservation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        // keep room_id in session and store it in variable
        $request->session()->put('room_id', $request->room_id);
        $room_id = $request->session()->get('room_id');
        // get dates from stored session
        $arrival = $request->session()->get('arrival');
        $departure = $request->session()->get('departure');
        if (Auth::user() && Auth::user()->id ==1) { 
           return view('admin.checkout', compact('arrival', 'room_id','departure'));
       }else{
           return view('dashboard.reservationCheckout', compact('arrival', 'room_id','departure'));
       }
    }

    /**
     * Store a newly created reservation in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // include functions we need
        include(app_path() . '\functions\n_rooms.php');
    
        //get info stored in sessions then Convert date format to Y-m-d (supported by mysql)
        $arrival = Carbon::createFromFormat('Y-m-d', $request->session()->get('arrival'))->format('Y-m-d');
        $departure = Carbon::createFromFormat('Y-m-d', $request->session()->get('departure'))->format('Y-m-d');
        $room_id = $request->session()->get('room_id');
       
        //get the price of the room
        $price = Room::select('price')->where('id',$room_id)->first();
        if(!$price){
        }
        // calculate price of total days of stay
        $price = $price->price * dateDifference($arrival, $departure);
        $room_type = Room::select('type')->where('id',$room_id)->first();
        // Create the request  
        if ( Auth::guest() || 1 == Auth::user()->id) {
            $validator = Validator::make($request->all(), [
                'email' => ['required','unique:users'],
                //'password' => ['required', 'string', 'min:8'],
                'mobile' => ['required','numeric'],
                'country' => ['required','string'],
                'name' => ['required','string'],
                ])->validate();
                $password = Str::random(8);
                $user = User::firstOrNew(['email' =>  $request->email]);
                $user->name = $request->name;
                $name = $request->name;
                $user->mobile = $request->mobile;
                $user->country = $request->country;
                $user->password = Hash::make($password);
                $user->save();
                $user_id = User::where('email',$request->email)->pluck('id')->toArray()[0];
                $email = $request->email;
                
            }else{  // store to variables to send email confirmation
                $user_id = Auth::user()->id;
                $name = Auth::user()->name;
                $email = Auth::user()->email;
                $password = null;
            }
            // store data to request 
           $request->request->add(['user_id' => $user_id]);
           $request->request->add(['arrival' => $arrival]);
           $request->request->add(['departure' => $departure]);
           $request->request->add(['price' => $price]);
           $request->request->add(['num_of_guests' => 2]);
           $request->request->add(['room_id' => $room_id]);
            // using Stripe to make transaction and make it optional for admin
            if (Auth::user()){
                $admin_id = Auth::user()->id;
            }else{ $admin_id = null;}
            if(1 == $admin_id  && !$request->stripeToken){
                Reservation::create($request->all());
            }else{
                Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
                Stripe\Charge::create ([
                "amount" => $price * 100,
                "currency" => "eur",
                "source" => $request->stripeToken,
                "description" => "Test payment from RoyalHotel.",
                "receipt_email" => $email,
                            ]);
        // send request
        Reservation::create($request->all());
            }
            
       // send Reservation Confirmation to user
        $details = ['price' => $price,
                    'client' => $name,
                    'arrival' => $arrival,
                    'departure' => $departure,
                    'room_type' => $room_type,
                    'password' => $password,
                ];

        \Mail::to($email)->send(new \App\Mail\MyTestMail($details));
    
        return redirect('home')->with('success', 'Your Booking has been confirmed')
                               ->with('name', $name);
    }

    /**
     * Display the specified reservation.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Reservation $reservation) 
    {
        // get reservations from database to edit
        $reservation = Reservation::with('room', 'room.hotel')
          ->get()
          ->find($reservation->id);
        // security check : show only user's reservations || admin can see all
        if ($reservation->user_id === Auth::user()->id || 1 === Auth::user()->id ) {
          $hotel_id = $reservation->room->hotel_id;
          $hotelInfo = Hotel::with('rooms')->get()->find($hotel_id);
      
          return view('dashboard.reservationSingle', compact('reservation', 'hotelInfo'));
        } else 
          return redirect('dashboard/reservations')->with('error', 'You are not authorized to see that.');
    }

    /**
     * Show the form for editing the specified reservation.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Reservation $reservation)
    {
       
        // security check : only admin is allowed
        if (1 == Auth::user()->id) {

             // get the requested reservation info
            $reservation = Reservation::with('room', 'room.hotel')
            ->get()
            ->find($reservation->id);
            $hotelInfo = Hotel::with('rooms')->get()->find(1);

            return view('dashboard.reservationEdit', compact('reservation', 'hotelInfo'));
        } else 
            return redirect('dashboard/reservations')->with('error', 'You are not authorized to do that');
    }

    /**
     * Update the specified reservation in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Reservation $reservation) {
        
        if (1 === Auth::user()->id)// check if admin then update reservation
        {
        $reservation->price = $request->price;
        $reservation->arrival = $request->arrival;
        $reservation->departure = $request->departure;
        $reservation->room_id = $request->room_id;
        $reservation->save();
      
        return redirect('dashboard/reservations')->with('success', 'Successfully updated your reservation!');
        }else{
           return redirect('dashboard/reservations')->with('error', 'You are not authorized to update this reservation'); 
        }
        
    }

    /**
     * Remove the specified reservation from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Reservation $reservation)
    {
     
        if (1 == Auth::user()->id ) { // check if admin then delete reservation
            $reservation = Reservation::find($reservation->id);
            $reservation->delete(); 
            return redirect('dashboard/reservations')->with('success', 'Successfully deleted your reservation!');
        } else
            return redirect('dashboard/reservations')->with('error', 'You are not authorized to delete this reservation');
    }
}
