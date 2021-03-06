<?php namespace Mrcore\Wiki\Http\Controllers;

use Auth;
use View;
use File;
use Cache;
use Input;
use Config;
use Layout;
use Request;
use Response;
use Redirect;
use Carbon\Carbon;
use Mreschke\Helpers\Str;
use Mrcore\Wiki\Models\Tag;
use Mrcore\Auth\Models\User;
use Mrcore\Wiki\Models\Mode;
use Mrcore\Wiki\Models\Post;
use Mrcore\Auth\Models\Role;
use Mrcore\Wiki\Models\Type;
use Mrcore\Wiki\Models\Badge;
use Mrcore\Wiki\Models\Format;
use Mrcore\Wiki\Models\Router;
use Mrcore\Wiki\Support\Crypt;
use Mrcore\Wiki\Models\Hashtag;
use Mrcore\Wiki\Models\PostTag;
use Mrcore\Auth\Models\UserRole;
use Mrcore\Wiki\Models\Revision;
use Mrcore\Wiki\Models\Framework;
use Mrcore\Wiki\Models\PostBadge;
use Mrcore\Auth\Models\Permission;
use Mrcore\Wiki\Models\PostPermission;
use Mrcore\Wiki\Support\Filemanager\Symlink;

use cogpowered\FineDiff\Diff;
use cogpowered\FineDiff\Granularity as DiffGranularity;
use cogpowered\FineDiff\Render as DiffRender;

class EditController extends Controller
{

    /**
     * Show Post Edit Form
     */
    public function editPost($id)
    {
        $post = Post::find($id);
        if (!isset($post)) {
            return Response::notFound();
        }
        if (!$post->hasPermission('write')) {
            return Response::denied();
        }

        // Adjust layout
        Layout::title($post->title);
        Layout::hideAll(true);
        Layout::container(false, false, false);

        // Decrypt content
        $post->content = Crypt::decrypt($post->content);

        // Check for uncommited revisions
        $uncommitted = Revision::where('post_id', '=', $id)->where('revision', '=', 0)->get();
        if (!empty($uncommitted)) {
            $uncommitted = $uncommitted->keyBy('id');
            $granularity = new DiffGranularity\Word;
            $diff = new Diff($granularity);
            $render = new DiffRender\Html;
            foreach ($uncommitted as $revision) {
                $revision->content = Crypt::decrypt($revision->content);
                $revision->diffOpcodes = $diff->getOpcodes($post->content, $revision->content);
                $revision->diffHtml = $render->process($post->content, $revision->diffOpcodes);
            }
        }

        // Get all formats
        $formats = Format::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all types
        $types = Type::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all frameworks
        $frameworks = Framework::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all modes
        $modes = Mode::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all badges
        $badges = Badge::all(['name', 'id'])->pluck('name', 'id')->all();
        $postBadges = $post->badges->pluck('id')->all();

        // Get all tags
        $tags = Tag::all(['name', 'id'])->pluck('name', 'id')->all();
        $postTags = $post->tags->pluck('id')->all();

        // Get hashtag
        $hashtag = Hashtag::findByPost($id);

        // Get All Possible Roles
        $roles = Role::orderBy('name')->get();

        // Get User Assigned Roles
        $userRoles = UserRole::where('user_id', Auth::user()->id)->get();

        // Get Permissions
        $perms = Permission::where('user_permission', '=', false)->get();

        // Get Post Permissions
        $postPerms = PostPermission::where('post_id', '=', $id)->get();

        // Get Post Routes
        $route = Router::findDefaultByPost($id);
        if ($route->static) {
            $defaultSlug = '/'.$route->slug;
        } else {
            $defaultSlug = '/'.$id.'/'.$route->slug;
        }

        return View::make('edit.edit', array(
            'post' => $post,
            'uncommitted' => $uncommitted,
            'formats' => $formats,
            'types' => $types,
            'frameworks' => $frameworks,
            'modes' => $modes,
            'badges' => $badges,
            'postBadges' => $postBadges,
            'tags' => $tags,
            'postTags' => $postTags,
            'hashtag' => $hashtag,
            'roles' => $roles,
            'userRoles' => $userRoles,
            'perms' => $perms,
            'postPerms' => $postPerms,
            'route' => $route,
            'defaultSlug' => $defaultSlug,
        ));
    }

    /**
     * Update post content only
     * Handles ajax $.post autosaves and actual publishing
     */
    public function updatePost($id)
    {

        // Ajax only controller
        if (!Request::ajax()) {
            return Response::notFound();
        }

        $post = Post::find($id);
        if (!isset($post)) {
            return Response::notFound();
        }
        if (!$post->hasPermission('write')) {
            return Response::denied();
        }

        $autosave = (Input::get('autosave') == 'true' ? true : false);
        $revision = Revision::where('post_id', '=', $id)
            ->where('revision', '=', 0)
            ->where('created_by', '=', Auth::user()->id)
            ->first();
        if (!isset($revision)) {
            $revision = new Revision;
            $revision->post_id = $id;
            $revision->title = $post->title;
            $revision->created_by = Auth::user()->id;
        }

        if ($autosave) {
            $revision->revision = 0;
        } else {
            // Update post
            $post->content = Crypt::encrypt(Input::get('content'));
            $post->teaser = Crypt::encrypt($post->createTeaser(Input::get('content')));
            $post->updated_by = Auth::user()->id;
            $post->save();

            // Clear this posts cache
            Post::forgetCache($id);

            // Bump up the revision
            $lastRevisionNum = 0;
            $lastRevision = Revision::where('post_id', '=', $id)->orderBy('revision', 'desc')->first();
            if (isset($lastRevision)) {
                $lastRevisionNum = $lastRevision->revision;
            }
            $revision->revision = $lastRevisionNum + 1;
        }
        $revision->content = Crypt::encrypt(Input::get('content'));
        $revision->created_at = Carbon::now();
        $revision->save();

        return 'saved';
    }

    /**
     * Update post organization settings only
     * Handles via ajax only
     */
    public function updatePostOrg($id)
    {
        // Ajax only controller
        if (!Request::ajax()) {
            return Response::notFound();
        }

        $post = Post::find($id);
        if (!isset($post)) {
            return Response::notFound();
        }
        if (!$post->hasPermission('write')) {
            return Response::denied();
        }

        // Update post info
        $post->format_id = Input::get('format');
        $post->type_id = Input::get('type');
        if ($post->type_id == Config::get('mrcore.wiki.app_type')) {
            $post->framework_id = Input::get('framework');
        } else {
            $post->framework_id = null;
        }
        $post->mode_id = Input::get('mode');
        if ($post->title != Input::get('title')) {
            // Title Changed
            $post->title = Input::get('title');

            // Update router if not a static route
            $route = Router::findDefaultByPost($id);
            if (!$route->static) {
                $route->slug = Input::get('slug');
                $route->save();
            }
        }

        $post->slug = Input::get('slug');
        $post->hidden = (Input::get('hidden') == 'true' ? true : false);
        $post->save();

        // Clear this posts cache
        Post::forgetCache($id);
        Router::forgetCache($id);

        // Update badges and tags
        PostBadge::set($id, Input::get('badges'));
        PostTag::set($id, Input::get('tags'));

        // New Tags
        $newTags = Input::get('new-tags');
        if ($newTags) {
            $tags = explode(",", $newTags);
            foreach ($tags as $tag) {
                $tag = strtolower(trim(
                    preg_replace('/[^\w-]+/i', '', $tag) # non alpha-numeric
                ));
                if (strlen($tag) >= 2) {
                    $tag = str_limit($tag, 50, '');
                    $getTag = Tag::where('name', $tag)->first();
                    if (!isset($getTag)) {
                        $newTag = new Tag;
                        $newTag->name = $tag;
                        $newTag->save();

                        $postTag = new PostTag;
                        $postTag->post_id = $id;
                        $postTag->tag_id = $newTag->id;
                        $postTag->save();
                    }
                }
            }
            Tag::forgetCache();
        }


        // Update hashtag
        if (!Hashtag::updateByPost($id, Input::get('hashtag'))) {
            return "ERROR: Hashtag already exists";
        }

        return "preferences saved";
    }

    /**
     * Delete post
     * Handles via ajax only
     */
    public function deletePost($id)
    {
        // Ajax only controller
        if (!Request::ajax()) {
            return Response::notFound();
        }

        $post = Post::find($id);
        if (!isset($post)) {
            return Response::notFound();
        }
        if (!$post->hasPermission('write')) {
            return Response::denied();
        }

        // Only admin or creator can delete post
        if (Auth::admin() || $post->created_by == Auth::user()->id) {
            $permanent = (Input::get('permanent') == 'true' ? true : false);

            if ($permanent) {
                // Delete Post and all foreign key references (leave files)
                $post->deletePost();

                // Rename folder
                File::move(Config::get('mrcore.wiki.files')."/index/$id", Config::get('mrcore.wiki.files')."/index/$id-deleted");
            } else {
                // Mark post as deleted
                $post->deleted = true;
                $post->save();
            }

            // Clear this posts cache
            Post::forgetCache($id);
            Router::forgetCache($id);

            return "post deleted";
        } else {
            return Response::denied();
        }
    }

    /**
     * Undelete post
     * Handles via ajax only
     */
    public function undeletePost($id)
    {
        // Ajax only controller
        if (!Request::ajax()) {
            return Response::notFound();
        }

        $post = Post::find($id);
        if (!isset($post)) {
            return Response::notFound();
        }
        if (!$post->hasPermission('write')) {
            return Response::denied();
        }

        // Only admin or creator can undelete post
        if (Auth::admin() || $post->created_by == Auth::user()->id) {

            // Undelete Post
            $post->deleted = false;
            $post->save();

            // Clear this posts cache
            Post::forgetCache($id);
            Router::forgetCache($id);

            return "post undeleted";
        } else {
            return Response::denied();
        }
    }

    /**
     * Update post permission settings only
     * Handles via ajax only
     */
    public function updatePostPerms($id)
    {
        // Ajax only controller
        if (!Request::ajax()) {
            return Response::notFound();
        }

        $post = Post::find($id);
        if (!isset($post)) {
            return Response::notFound();
        }
        if (!$post->hasPermission('write')) {
            return Response::denied();
        }

        // Update post info
        $post->shared = (Input::get('shared') == 'true' ? true : false);
        $post->save();

        // Clear this posts cache
        Post::forgetCache($id);

        // Update post permissions
        $perms = json_decode(Input::get('perms'));

        PostPermission::where('post_id', '=', $id)->delete();
        foreach ($perms as $perm) {
            $postPermission = new PostPermission;
            $postPermission->post_id = $id;
            $postPermission->permission_id = $perm->perm_id;
            $postPermission->role_id = $perm->role_id;
            $postPermission->save();
        }

        return "preferences saved";
    }

    /**
     * Update post advanced settings only
     * Handles via ajax only
     */
    public function updatePostAdv($id)
    {
        // Ajax only controller
        if (!Request::ajax()) {
            return Response::notFound();
        }

        $post = Post::find($id);
        if (!isset($post)) {
            return Response::notFound();
        }
        if (!$post->hasPermission('write')) {
            return Response::denied();
        }

        $ret = "preferences saved";
        if (Auth::admin()) {
            // Post must be type=app and framework=workbench
            if ($post->type->constant != 'app' || $post->framework->constant != 'workbench') {
                return "ERROR: post must be type=app and framework=workbench";
            }

            $defaultSlug = trim(Input::get('default-slug'));
            $defaultSlug = preg_replace("'//'", "/", $defaultSlug);
            if ($defaultSlug == '/') {
                $defaultSlug = '';
            }
            if ($defaultSlug) {
                $static = true;
            } else {
                $static = false;
            }
            $symlink = (Input::get('symlink') == 'true' ? true : false);
            if ($static == false) {
                $symlink = false;
            }
            $workbench = strtolower(Input::get('workbench'));
            if (!$workbench) {
                $workbench = null;
            }
            if (isset($workbench)) {
                if (substr_count($workbench, '/') != 1) {
                    return "ERROR: workbench must be vendor/package format";
                }
            }

            if (substr($defaultSlug, 0, 1) == '/') {
                $defaultSlug = substr($defaultSlug, 1);
            }
            if (substr($defaultSlug, -1) == '/') {
                $defaultSlug = substr($defaultSlug, 0, -1);
            }

            // Update post info
            $post->symlink = $symlink;
            $post->workbench = $workbench;
            $post->save();

            // Clear this posts cache
            Post::forgetCache($id);

            // Update router
            $route = Router::findDefaultByPost($id);
            $originalRoute = Router::findDefaultByPost($id);
            $valid = true;
            if ($static) {
                $route->slug = $defaultSlug;

                // Don't Allow integer static routes
                if ($valid) {
                    $tmp = explode("/", $defaultSlug);
                    if (is_numeric($tmp[0])) {
                        $ret = "ERROR: Static route cannot begin with an integer";
                        $valid = false;
                    }
                }

                // Check for duplicate route
                if ($valid) {
                    $dup = Router::where('slug', $defaultSlug)
                        ->where('disabled', false)
                        ->where('post_id', '!=', $id)
                        ->first();
                    if ($dup) {
                        $ret = "ERROR: Route already exists";
                        $valid = false;
                    }
                }

                // Don't allow url reserved words
                if ($valid) {
                    $tmp = explode("/", $defaultSlug);
                    if (in_array($tmp[0], Config::get('mrcore.wiki.reserved_routes'))) {
                        $ret = "ERROR: Static route cannot be '$tmp[0]', this is a reserved word";
                        $valid = false;
                    }
                }
            } else {
                $route->slug = $post->slug;
            }

            if ($valid) {
                // Save Route
                $route->static = $static;
                $route->save();

                Router::forgetCache($route->slug);
                Router::forgetCache($id);
                Router::forgetCache($originalRoute->slug);

                // Symlink Management, only create initially and only if static
                if ($static) {
                    $symlink = new Symlink($post, $originalRoute);
                    $symlinkReturn = $symlink->manage();
                    if (isset($symlinkReturn)) {
                        $ret = $symlinkReturn;
                    }
                }
            }
        }

        return $ret;
    }

    /**
     * Show Post Create Form
     */
    public function newPost()
    {
        // Controller Security
        if (!User::hasPermission('create')) {
            return Response::denied();
        }

        // Get all formats
        $formats = Format::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all types
        $types = Type::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all frameworks
        $frameworks = Framework::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all badges
        $badges = Badge::all(['name', 'id'])->pluck('name', 'id')->all();

        // Get all tags
        $tags = Tag::all(['name', 'id'])->pluck('name', 'id')->all();

        return View::make('edit.new', array(
            'formats' => $formats,
            'types' => $types,
            'frameworks' => $frameworks,
            'badges' => $badges,
            'tags' => $tags,
        ));
    }

    /**
     * Create new post
     */
    public function createPost()
    {
        // Controller Security
        if (!User::hasPermission('create')) {
            return Response::denied();
        }

        // Get Pre-validated input
        $formatID = Input::get('format');
        $typeID = Input::get('type');
        $frameworkID = null;
        if ($typeID == Config::get('mrcore.wiki.app_type')) {
            $frameworkID = Input::get('framework');
        }
        $modeID = Mode::where('constant', '=', 'default')->first()->id;

        // Start new post
        $post = new Post;
        $post->uuid = Str::getGuid();
        $post->title = Input::get('title');
        $post->slug = Input::get('slug');
        $post->content = Crypt::encrypt('');
        $post->teaser = Crypt::encrypt('');
        $post->contains_script = false;
        $post->contains_html = false;
        $post->format_id = $formatID;
        $post->type_id = $typeID;
        $post->framework_id = $frameworkID;
        $post->mode_id = $modeID;
        $post->symlink = false;
        $post->shared = false;
        $post->hidden = false;
        $post->deleted = false;
        $post->indexed_at = '1900-01-01 00:00:00';
        $post->created_by = Auth::user()->id;
        $post->updated_by = Auth::user()->id;
        $post->save();

        // Add route
        $route = new Router;
        $route->slug = Input::get('slug');
        $route->post_id = $post->id;
        $route->save();

        // Clear this posts cache
        Post::forgetCache($post->id);

        // Updates badges and tags
        PostBadge::set($post->id, Input::get('badges'));
        PostTag::set($post->id, Input::get('tags'));

        // Create folder
        try {
            mkdir(Config::get('mrcore.wiki.files')."/index/$post->id");
        } catch (\Exception $e) {
        }

        // Redirect to full edit page
        return Redirect::route('edit', array('id' => $post->id));
    }
}
