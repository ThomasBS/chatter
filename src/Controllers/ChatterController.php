<?php

namespace DevDojo\Chatter\Controllers;

use Auth;
use DevDojo\Chatter\Models\Models;
use Illuminate\Routing\Controller as Controller;

class ChatterController extends Controller
{
    public function index($slug = '')
    {
        $pagination_results = config('chatter.paginate.num_of_results');

        $discussions = Models::discussion()->with('user')->with('post')->with('postsCount')->with('category')->orderBy('updated_at', 'DESC')->paginate($pagination_results);
        if (isset($slug) && $slug) {
            $category = Models::category()->where('slug', '=', $slug)->first();
            if (isset($category->id)) {
                $discussions = Models::discussion()->with('user')->with('post')->with('postsCount')->with('category')->where('chatter_category_id', '=', $category->id)->orderBy('updated_at', 'DESC')->paginate($pagination_results);
            } else {
                abort(404);
            }
        }

        $stickyDiscussions = Models::discussion()
            ->where('sticky', true)
            ->with('user')
            ->with('post')
            ->with('postsCount')
            ->with('category')
            ->orderBy('updated_at', 'DESC')
            ->get();

        $categories = Models::category()->all();
        $chatter_editor = config('chatter.editor');

        // Dynamically register markdown service provider
        \App::register('GrahamCampbell\Markdown\MarkdownServiceProvider');

        return view('chatter::home', compact('stickyDiscussions', 'discussions', 'categories', 'chatter_editor'));
    }

    public function login()
    {
        if (!Auth::check()) {
            return \Redirect::to('/'.config('chatter.routes.login').'?redirect='.config('chatter.routes.home'))->with('flash_message', 'Please create an account before posting.');
        }
    }

    public function register()
    {
        if (!Auth::check()) {
            return \Redirect::to('/'.config('chatter.routes.register').'?redirect='.config('chatter.routes.home'))->with('flash_message', 'Please register for an account.');
        }
    }
}
