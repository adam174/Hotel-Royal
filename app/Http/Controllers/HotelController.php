<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Reservation;
use App\Models\Room;
use Auth;


class HotelController extends Controller
{
    public function index() {
      //  $hotels = Hotel::all();
       if (Auth::user() && Auth::user()->id == 1) {
           return view('admin.hotels');
       }
        return view('hotels');
    }
     
    public function create(Request $request)
    {
        // inluding the function to calculate days of bookings 
        include(app_path() . '\functions\n_rooms.php'); 
        // storing form inputs to variables then save to session
        $arrival = $request->arrival;
        $departure = $request->departure;
        $payment = $request->payment;
        $request->session()->put('arrival', $request->arrival);
        $request->session()->put('departure', $request->departure);
        $request->session()->put('payment', $request->payment);
        // get the rooms ids which is reserved in the requested dates  
        $arrr = Reservation::select('room_id')->whereBetween('arrival',array($arrival,$departure))->orwhereBetween('departure',array($arrival,$departure))->get('room_id')->toArray();
        $arrr = array_column($arrr, 'room_id');
        // declare an empty array then store the roomsid which was booked more than available rooms ( n_rooms)
        $dups = array();
        foreach(array_count_values($arrr) as $val => $c)  if($c >= Room::where('id',$val)->pluck('n_rooms')->toArray()[0] ) $dups[] = $val;
        //$arr_2 = array_diff($arrr, $dups);
        //$dups = array_values($arr_2);
        //Get available rooms
        $hotelInfo = Room::where('hotel_id',1)->whereNotIn('id', $dups)->get();
        if (Auth::user() && Auth::user()->id == 1) {
            return view('admin.reservationCreate', compact('arrival', 'hotelInfo','departure','payment'));
        }

        return view('dashboard.reservationCreate', compact('arrival', 'hotelInfo','departure'));
    }
}