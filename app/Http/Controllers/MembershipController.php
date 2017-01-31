<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App;
use Carbon\Carbon;
use App\Group;
use App\User;
use Gate;

class MembershipController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth', ['only' => ['join', 'joinForm', 'leave', 'leaveForm', 'settings', 'settingsForm']]);
        $this->middleware('public', ['only' => ['joinForm', 'join']]);
    }

    /**
    * Show a settings screen for a specific group. Allows a user to join, leave, set subscribe settings.
    */
    public function joinForm(Request $request,  Group $group)
    {
        if (Gate::allows('join', $group))
        {
            // load or create membership for this group and user combination
            $membership = \App\Membership::firstOrNew(['user_id' => $request->user()->id, 'group_id' => $group->id]);

            return view('membership.join')
            ->with('group', $group)
            ->with('tab', 'settings')
            ->with('membership', $membership)
            ->with('interval', 'daily');
        }
        else
        {
            flash()->info( trans('messages.not_allowed'));
            return redirect()->back();
        }

    }

    /**
    * Store the membership. It means: add a user to a group and store his/her notification settings.
    *
    * @param  Request $request  [description]
    * @param  [type]  $group_id [description]
    *
    * @return [type]            [description]
    */
    public function join(Request $request, Group $group)
    {
        if (Gate::allows('join', $group))
        {
            // load or create membership for this group and user combination
            $membership = \App\Membership::firstOrNew(['user_id' => $request->user()->id, 'group_id' => $group->id]);
            $membership->membership = \App\Membership::MEMBER;
            $membership->notification_interval = $this->intervalToMinutes($request->get('notifications'));

            // we prented the user has been already notified once, now. The first mail sent will be at the choosen interval from now on.
            $membership->notified_at = Carbon::now();
            $membership->save();

            return redirect()->action('GroupController@show', [$group->id])->with('message', trans('membership.welcome'));
        }
        else
        {
            flash()->info( trans('messages.not_allowed'));
            return redirect()->back();
        }
    }


    /**
    * Show a settings screen for a specific group. Allows a user to leave the group.
    */
    public function leaveForm(Request $request, Group $group)
    {
        // load or create membership for this group and user combination
        $membership = \App\Membership::firstOrNew(['user_id' => $request->user()->id, 'group_id' => $group->id]);

        return view('membership.leave')
        ->with('group', $group)
        ->with('tab', 'settings')
        ->with('membership', $membership);
    }


    /**
    * Remove the specified user from the group.
    *
    * @param  int  $id
    *
    * @return Response
    */
    public function leave(Request $request, Group $group)
    {
        // load or create membership for this group and user combination
        $membership = \App\Membership::where(['user_id' => $request->user()->id, 'group_id' => $group->id])->firstOrFail();
        $membership->membership = \App\Membership::UNREGISTERED;
        $membership->save();
        return redirect()->action('DashboardController@index');

    }



    /**
    * Force add a member to a group (admin feature)
    * This is the form that allows an admin to select a user to add to a group
    */
    public function addUserForm(Request $request, Group $group)
    {

        $this->authorize('add-member', $group);

        // load a list of users not yet in this group
        $members = $group->users;
        $notmembers = \App\User::whereNotIn('id', $members->pluck('id'))->orderBy('name')->pluck('name', 'id');

        return view('membership.add')
        ->with('group', $group)
        ->with('members', $members)
        ->with('notmembers', $notmembers)
        ->with('tab', 'users');

    }


    /**
    * Force add a member to a group (admin feature)
    * Processing form's content
    */
    public function addUser(Request $request, Group $group)
    {
        $this->authorize('add-member', $group);

        if ($request->has('users'))
        {
            foreach ($request->get('users') as $user_id)
            {
                $user = \App\User::findOrFail($user_id);
                // load or create membership for this group and user combination
                $membership = \App\Membership::firstOrNew(['user_id' => $user->id, 'group_id' => $group->id]);
                $membership->membership = \App\Membership::MEMBER;
                $membership->notification_interval = $this->intervalToMinutes('weekly'); // this is a sane default imho for notification interval

                // we prented the user has been already notified once, now. The first mail sent will be at the choosen interval from now on.
                $membership->notified_at = Carbon::now();
                $membership->save();

                flash()->info(trans('messages.user_added_successfuly') . ' : ' . $user->name);
            }
        }

        return redirect()->action('UserController@index', $group);
    }


    /**
    * Force remove a member to a group (admin feature)
    * This is must be called from a delete form
    */
    public function removeUser(Request $request, Group $group, User $user)
    {
        $this->authorize('remove-member', $group);
        $membership = \App\Membership::where(['user_id' => $user->id, 'group_id' => $group->id])->firstOrFail();
        $membership->delete();
        flash()->info(trans('messages.user_removed_successfuly') . ' : ' . $user->name);
        return redirect()->action('UserController@index', $group);
    }


    public function adminForm(Request $request, Group $group, User $user)
    {
        $this->authorize('edit-member', $group);

        return view('membership.admin')
        ->with('group', $group)
        ->with('user', $user)
        ->with('tab', 'users');

    }


    /**
    * Set a member of a group to admin (admin feature)
    */
    public function addAdminUser(Request $request, Group $group, User $user)
    {
        $this->authorize('add-admin', $group);

        $membership = \App\Membership::where(['user_id' => $user->id, 'group_id' => $group->id])->firstOrFail();
        $membership->membership = \App\Membership::ADMIN;
        $membership->save();
        flash()->info(trans('messages.user_made_admin_successfuly') . ' : ' . $user->name);
        return redirect()->action('UserController@index', $group);
    }


    /**
    * Set a member of a group to admin (admin feature)
    */
    public function removeAdminUser(Request $request, Group $group, User $user)
    {
        $this->authorize('remove-admin', $group);

        $membership = \App\Membership::where(['user_id' => $user->id, 'group_id' => $group->id])->firstOrFail();
        $membership->membership = \App\Membership::MEMBER;
        $membership->save();
        flash()->info(trans('messages.user_made_member_successfuly') . ' : ' . $user->name);
        return redirect()->action('UserController@index', $group);
    }


    /**
    * Show a settings screen for a specific group. Allows a user to join, leave, set subscribe settings.
    */
    public function settingsForm(Request $request, Group $group)
    {
        // load or create membership for this group and user combination
        $membership = \App\Membership::firstOrNew(['user_id' => $request->user()->id, 'group_id' => $group->id]);

        return view('membership.edit')
        ->with('tab', 'settings')
        ->with('group', $group)
        ->with('interval', $this->minutesToInterval($membership->notification_interval))
        ->with('membership', $membership);
    }


    /**
    * Store new settings from the settingsForm
    */
    public function settings(Request $request, Group $group)
    {
        // load or create membership for this group and user combination
        $membership = \App\Membership::firstOrNew(['user_id' => $request->user()->id, 'group_id' => $group->id]);
        $membership->membership = \App\Membership::MEMBER;
        $membership->notification_interval = $this->intervalToMinutes($request->get('notifications'));
        $membership->save();

        return redirect()->action('GroupController@show', [$group->id])->with('message', trans('membership.settings_updated'));
    }




    /**
    * Show an explanation page on how to join a private group
    */
    public function howToJoin(Request $request, Group $group)
    {
        return view('membership.howtojoin')
        ->with('tab', 'settings')
        ->with('group', $group);
    }


    function intervalToMinutes($interval)
    {
        $minutes = -1;

        switch ($interval) {
            case 'hourly':
            $minutes = 60;
            break;
            case 'daily':
            $minutes = 60 * 24;
            break;
            case 'weekly':
            $minutes = 60 * 24 * 7;
            break;
            case 'biweekly':
            $minutes = 60 * 24 * 14;
            break;
            case 'monthly':
            $minutes = 60 * 24 * 30;
            break;
            case 'never':
            $minutes = -1;
            break;
        }
        return $minutes;
    }

    function minutesToInterval($minutes)
    {
        $interval = 'never';

        switch ($minutes) {
            case 60:
            $interval = 'hourly';
            break;
            case 60 * 24:
            $interval = 'daily';
            break;
            case 60 * 24 * 7:
            $interval = 'weekly';
            break;
            case 60 * 24 * 14:
            $interval = 'biweekly';
            break;
            case 60 * 24 * 30:
            $interval = 'monthly';
            break;
            case -1:
            $interval = 'never';
            break;
        }

        return $interval;
    }

}
