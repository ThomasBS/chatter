<?php

namespace DevDojo\Chatter\Controllers;

use Auth;
use Carbon\Carbon;
use DevDojo\Chatter\Events\ChatterAfterDeleteDiscussion;
use DevDojo\Chatter\Events\ChatterAfterNewResponse;
use DevDojo\Chatter\Events\ChatterBeforeNewResponse;
use DevDojo\Chatter\Events\ChatterBeforeUpdateResponse;
use DevDojo\Chatter\Mail\ChatterDiscussionUpdated;
use DevDojo\Chatter\Models\Models;
use Event;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as Controller;
use Illuminate\Support\Facades\Mail;
use Validator;

class ChatterPostController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return response()->json([]);
        $total = 10;
        $offset = 0;
        if ($request->total) {
            $total = $request->total;
        }
        if ($request->offset) {
            $offset = $request->offset;
        }
        $posts = Models::post()->with('user')->orderBy('created_at', 'DESC')->take($total)->offset($offset)->get();

        return response()->json($posts);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $stripped_tags_body = ['body' => strip_tags($request->body)];
        $validator = Validator::make($stripped_tags_body, [
            'body' => 'required|min:2',
        ]);

        Event::fire(new ChatterBeforeNewResponse($request, $validator));
        if (function_exists('chatter_before_new_response')) {
            chatter_before_new_response($request, $validator);
        }

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        if (config('chatter.security.limit_time_between_posts')) {
            if ($this->notEnoughTimeBetweenPosts()) {
                $minute_copy = (config('chatter.security.time_between_posts') == 1) ? ' minute' : ' minutes'; // TODO: Add to translation
                $chatter_alert = [
                    'chatter_alert_type' => 'danger',
                    'chatter_alert'      => trans('chatter::alerts.spam_prevention_notice:mins', ['mins' => config('chatter.security.time_between_posts')]),
                    ];

                return back()->with($chatter_alert)->withInput();
            }
        }

        $request->request->add(['user_id' => Auth::user()->id]);

        if (config('chatter.editor') == 'simplemde'):
            $request->request->add(['markdown' => 1]);
        endif;

        $new_post = Models::post()->create($request->all());

        $discussion = Models::discussion()->find($request->chatter_discussion_id);
        if ($discussion) {
            $discussion->updated_at = date('Y-m-d H:i:s');
            $discussion->save();
        }

        $category = Models::category()->find($discussion->chatter_category_id);
        if (!isset($category->slug)) {
            $category = Models::category()->first();
        }

        if ($new_post->id) {
            Event::fire(new ChatterAfterNewResponse($request));
            if (function_exists('chatter_after_new_response')) {
                chatter_after_new_response($request);
            }

            // if email notifications are enabled
            if (config('chatter.email.enabled')) {
                // Send email notifications about new post
                $this->sendEmailNotifications($new_post->discussion);
            }

            $chatter_alert = [
                'chatter_alert_type' => 'success',
                'chatter_alert'      => trans('chatter::alerts.response_submit_success'),
                ];

            return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$discussion->slug)->with($chatter_alert);
        } else {
            $chatter_alert = [
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => trans('chatter::alerts.response_submit_fail'),
                ];

            return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$discussion->slug)->with($chatter_alert);
        }
    }

    private function notEnoughTimeBetweenPosts()
    {
        $user = Auth::user();

        $past = Carbon::now()->subMinutes(config('chatter.security.time_between_posts'));

        $last_post = Models::post()->where('user_id', '=', $user->id)->where('created_at', '>=', $past)->first();

        if (isset($last_post)) {
            return true;
        }

        return false;
    }

    private function sendEmailNotifications($discussion)
    {
        $users = $discussion->users->except(Auth::user()->id);
        foreach ($users as $user) {
            Mail::to($user)->queue(new ChatterDiscussionUpdated($discussion));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $stripped_tags_body = ['body' => strip_tags($request->body)];
        $validator = Validator::make($stripped_tags_body, [
            'body' => 'required|min:2',
        ]);

        Event::fire(new ChatterBeforeUpdateResponse($request, $validator));

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $post = Models::post()->find($id);
        if (!Auth::guest() && (Auth::user()->id == $post->user_id)) {
            $allowedTags = [
                '<p>',
                '<b>',
                '<strong>',
                '<i>',
                '<em>',
                '<span>',
                '<ul>',
                '<ol>',
                '<li>',
                '<a>',
                '<u>',
                '<div>'
            ];
            $post->body = strip_tags($request->body, implode(',', $allowedTags));
            $post->save();

            $discussion = Models::discussion()->find($post->chatter_discussion_id);

            $category = Models::category()->find($discussion->chatter_category_id);
            if (!isset($category->slug)) {
                $category = Models::category()->first();
            }

            $chatter_alert = [
                'chatter_alert_type' => 'success',
                'chatter_alert'      => trans('chatter::alerts.response_update_success'),
                ];

            return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$discussion->slug)->with($chatter_alert);
        } else {
            $chatter_alert = [
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => trans('chatter::alerts.response_update_fail'),
                ];

            return redirect('/'.config('chatter.routes.home'))->with($chatter_alert);
        }
    }

    /**
     * Delete post.
     *
     * @param string $id
     * @param  \Illuminate\Http\Request
     *
     * @return \Illuminate\Routing\Redirect
     */
    public function destroy($id, Request $request)
    {
        $post = Models::post()->with('discussion')->findOrFail($id);

        if ($request->user()->id !== (int) $post->user_id) {
            return redirect('/'.config('chatter.routes.home'))->with([
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => trans('chatter::alerts.response_delete_fail'),
            ]);
        }

        if ($post->discussion->posts()->oldest()->first()->id === $post->id) {
            $post->discussion->posts()->delete();
            $post->discussion()->delete();
            Event::fire(new ChatterAfterDeleteDiscussion($post->discussion->id));

            return redirect('/'.config('chatter.routes.home'))->with([
                'chatter_alert_type' => 'success',
                'chatter_alert'      => trans('chatter::alerts.response_and_discussion_delete_success'),
            ]);
        }

        $post->delete();

        $url = '/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$post->discussion->category->slug.'/'.$post->discussion->slug;

        return redirect($url)->with([
            'chatter_alert_type' => 'success',
            'chatter_alert'      => trans('chatter::alerts.response_delete_success'),
        ]);
    }
}
