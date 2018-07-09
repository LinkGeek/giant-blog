<?php

namespace App\Console\Commands\Migration;

use App\Models\Category;
use App\Models\Tag;
use Illuminate\Console\Command;
use DB;
use App\Models\Chat;
use HyperDown\Parser;
use App\Models\Config;
use App\Models\Article;
use App\Models\Comment;
use App\Models\OauthUser;
use App\Models\ArticleTag;
use App\Models\FriendshipLink;
use League\HTMLToMarkdown\HtmlConverter;
use Artisan;

class FromThinkPHPBjyBlog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migration:fromThinkPHPBjyBlog';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 从 thinkphp-bjyblog 迁移数据
     */
    public function handle()
    {
        $articleModel = new Article();
        $articleTagModel = new ArticleTag();
        $commentModel = new Comment();
        $friendshipLinkModel = new FriendshipLink();
        $configModel = new Config();
        $oauthUserModel = new OauthUser();
        $chatModel = new Chat();
        $categoryModel = new Category();
        $tagModel = new Tag();
        // 防止误操作清空数据库
        if (file_exists(storage_path('lock/migration.lock'))) {
            die('已经迁移过,如需重新迁移,请先删除/storage/lock/migration.lock文件');
        }
        Artisan::call('seeder:clear');

        // 从旧系统中迁移分类表
        $data = DB::connection('old')->table('category')->get()->toArray();
        foreach ($data as $v) {
            $category = [
                'id' => $v->cid,
                'name' => $v->cname,
                'keywords' => $v->keywords,
                'description' => $v->description,
                'sort' => $v->sort,
                'pid' => $v->pid,
            ];
            $categoryModel->storeData($category);
        }

        // 从旧系统中迁移标签表
        $data = DB::connection('old')->table('tag')->get()->toArray();
        foreach ($data as $v) {
            $category = [
                'id' => $v->tid,
                'name' => $v->tname,
            ];
            $tagModel->storeData($category);
        }

        // 从旧系统中迁移文章
        $htmlConverter = new HtmlConverter();
        $parser = new Parser();
        $data = DB::connection('old')
            ->table('article')
            ->get()
            ->toArray();
        $articleModel->where('id', '<', 87)->forceDelete();
        foreach ($data as $k => $v) {
            $content = htmlspecialchars_decode($v->content);
            $content = str_replace('<br style="box-sizing: inherit; margin-bottom: 0px;"/>', '', $content);
            $content = str_replace('/Upload/image/ueditor', '/uploads/article', $content);
            $content = str_replace(['<pre class="brush:', '</pre>', ';toolbar:false">',  '<p><br/></p>'], ["\r\n```", "\r\n```\r\n", "\r\n", "\r\n"], $content);
            $content = str_replace('```js', '```javascript', $content);
            $content = str_replace("\r\n", '|rn|', $content);
            $content = str_replace('<p>', '', $content);
            $content = str_replace('</p>', '|rn|', $content);
            $content = str_replace('&nbsp;', '|nbsp|', $content);
            $markdown = $htmlConverter->convert($content);
            $markdown = htmlspecialchars($markdown);
            $markdown = str_replace(['|rn|', '\*', '\_', "\n "], ["\r\n", '*', '_', "\n    "], $markdown);
            $markdown = str_replace("\r\n\r\n", "\r\n", $markdown);
            $markdown = str_replace('http://www.baijunyao.com/uploads/article', 'uploads/article', $markdown);
            $markdown = str_replace('|nbsp|', '&nbsp;', $markdown);
            preg_match_all("/\/\/\*+.*\*+/", $markdown, $arr);
            $tmp = [];
            foreach ($arr[0] as $m => $n) {
                $tmp[$m] = str_replace('*', '\*', $n);
            }
            $markdown = str_replace($arr[0], $tmp, $markdown);
            // markdown 转html
            $html = $parser->makeHtml($markdown);
            $html = html_entity_decode($html);
            $html = str_replace(['<code class="', '\\\\'], ['<code class="lang-', '\\'], $html);
            $article = [
                'id' => $v->aid,
                'category_id' => $v->cid,
                'title' => $v->title,
                'author' => $v->author,
                'markdown' => $markdown,
                'html' => $html,
                'description' => $v->description,
                'keywords' => $v->keywords,
                'cover' => $articleModel->getCover($markdown),
                'is_top' => $v->is_top,
                'click' => $v->click,
            ];
            $articleModel->create($article);
            $editArticleMap = [
                'id' => $v->aid
            ];

            $editArticleData = [
                'created_at' => date('Y-m-d H:i:s', $v->addtime)
            ];
            $articleModel->updateData($editArticleMap, $editArticleData);
        }

        // 从旧系统中迁移文章标签中间表
        $data = DB::connection('old')->table('article_tag')->get()->toArray();
        foreach ($data as $v) {
            $article_tag = [
                'article_id' => $v->aid,
                'tag_id' => $v->tid
            ];
            $articleTagModel->storeData($article_tag);
        }

        // 从旧系统中迁移评论
        $data = DB::connection('old')
            ->table('comment')
            // ->where('cmtid', 1614)
            ->orderBy('cmtid', 'desc')
            ->get()
            ->toArray();
        foreach ($data as $v) {
            // 把img标签反转义
            $content = html_entity_decode(htmlspecialchars_decode($v->content));
            // 匹配图片
            preg_match_all('/<img.*?title="(.*?)".*?>/i', $content, $img);
            $search = $img[0];
            $replace = array_map(function ($v) {
                return '['.$v.']';
            }, $img[1]);
            $content = str_replace($search, $replace, $content);
            $content = strip_tags($content);
            $comment_data = [
                'id' => $v->cmtid,
                'oauth_user_id' => $v->ouid,
                'type' => $v->type,
                'pid' => $v->pid,
                'article_id' => $v->aid,
                'content' => $content,
                'status' => $v->status,
            ];
            $commentModel->create($comment_data);
            $editCommentMap = [
                'id' => $v->cmtid,
            ];
            $editCommentData = [
                'created_at' => date('Y-m-d H:i:s', $v->date)
            ];
            $commentModel->updateData($editCommentMap, $editCommentData);
        }

        // 迁移友情链接
        $data = DB::connection('old')->table('link')->get()->toArray();
        foreach ($data as $v) {
            $link_data = [
                'id' => $v->lid,
                'name' => $v->lname,
                'url' => $v->url,
                'sort' => $v->sort
            ];
            if ($v->is_show === 0) {
                $link_data['deleted_at'] = date('Y-m-d H:i:s', time());
            }
            $friendshipLinkModel->storeData($link_data);
        }

        // 迁移配置项
        $data = DB::connection('old')->table('config')->get()->toArray();
        foreach ($data as $v) {
            $updateMap = [
                'name' => $v->name
            ];
            $updateData = [
                'name' => $v->name,
                'value' => $v->value
            ];
            // 判断是否有此配置项；如果有；则修改；如果没有则新增
            $count = $configModel->whereMap($updateMap)->count();
            if ($count) {
                $configModel->updateData($updateMap, $updateData);
            } else {
                $configModel->storeData($updateData);
            }
        }

        // 迁移第三方登录用户表
        $data = DB::connection('old')->table('oauth_user')->get()->toArray();
        foreach ($data as $v) {
            $oauthUserData = [
                'id' => $v->id,
                'type' => $v->type,
                'name' => $v->nickname,
                'avatar' => $v->head_img,
                'openid' => $v->openid,
                'access_token' => $v->access_token,
                'last_login_ip' => $v->last_login_ip,
                'login_times' => $v->login_times,
                'email' => $v->email,
                'is_admin' => $v->is_admin
            ];
            $oauthUserModel->storeData($oauthUserData);
            $editOauthUserMap = [
                'id' => $v->id,
            ];
            $editOauthUserData = [
                'created_at' => date('Y-m-d H:i:s', $v->create_time)
            ];
            $oauthUserModel->updateData($editOauthUserMap, $editOauthUserData);
        }

        // 迁移随言碎语表
        $data = DB::connection('old')->table('chat')->get()->toArray();
        foreach ($data as $v) {
            $chatData = [
                'id' => $v->chid,
                'content' => $v->content,
            ];
            $chatModel->storeData($chatData);
            $editChatMap = [
                'id' => $v->chid,
            ];
            $editChatData = [
                'created_at' => date('Y-m-d H:i:s', $v->date)
            ];
            $chatModel->updateData($editChatMap, $editChatData);
        }
        $this->info('从旧系统迁移数据完成');
        // 迁移完成创建锁文件
        file_put_contents(storage_path('lock/migration.lock'), '');
    }

    /**
     * 把用户的头像保存到本地
     *
     * @param OauthUser $oauthUserModel
     */
    public function avatar(OauthUser $oauthUserModel)
    {
        $data = $oauthUserModel->select('id', 'avatar')->get();
        foreach ($data as $k => $v) {
            $editMap = [
                'id' => $v->id
            ];
            if (strpos($v->avatar, 'http') !== false) {
                $avatarPath = 'uploads/avatar/'.$v->id.'.jpg';
                file_put_contents(public_path($avatarPath), curl_get_contents($v->avatar));
                $editData = [
                    'avatar' => '/'.$avatarPath
                ];
                $oauthUserModel->updateData($editMap, $editData);
            }
        }
    }
}
