# 数据结构

## Post (活动/课程/文章/问题/解答/话题/讨论/计划/打卡/评论/图片/封面/音频/视频/拼图主题/拼图模板/拼图作品/反馈)

    {
    	"id":"",
    	"type":"", // event(活动), course(课程), article(文章), question(问题), answer(解答), topic(话题), discussion(讨论), comment(评论), image(图片), poster(封面), audio(音频), video(视频), collage_theme(拼图主题), collage_template(拼图模板), collage(拼图作品), feedback(反馈)
    	"title":"" // 标题 
    	"abbreviation":"" // 编辑标题, 较标题更短
    	"author":user, // 作者, 活动主持人, 课程讲师
		"is_anonymous":false // 是否匿名发布
		"visibility":'public', // 公开度, public或private, 默认public
        "excerpt", // 摘要, 活动介绍, 课程副标题
    	"content":"", // 详情
    	"url":"", // 链接(图片, 封面),
    	"status":"", // 状态(活动:进行中/已解答, 问题:专家已回答),
    	
    	/*以下字段可排序*/
		"views":"0", // 阅读数(文章, 问题)
        "likes":"0", // 点赞数(活动, 文章, 问题, 解答, 评论)
		"reposts":"0", // 转发数(活动, 文章, 问题, 解答, 评论)
		"comments_count":"0", // 问题数(活动), 回答数(问题), 评论数(文章, 解答),
		"favored_users_count":"0" //
		"weight":0, // 权重
    	"created_at":"2015-01-01 00:00:00", // 发布时间
    	"updated_at":"2015-01-01 00:00:00", // 更新时间
        
    	"created_at_human":"刚刚", // 友好显示的发布时间
    	"updated_at_human":"刚刚", // 友好显示的更新时间
    	
        "liked":false, // 已点赞(若用户未登陆, 为false),
        "premium":true, // 需要付费购买
        "paid":true, // 仅当premium为true时
        "price":1.00, // 单位: 元人民币, 仅当premium为true时
        "price_promotion":1.00, // 优惠价, 仅详情请求且query含有promotion_code时
        "attended":"", // pending:审核中, accepted:报名成功, declined:报名失败(若用户未登陆, 为false)
        "favored":"", // 已收藏
        "membership":"", // 权限会员等级(10, 20, 30)
        "membership_label", // 权限会员等级名称(金卡, 白金, 黑金)
        
        "poster":post, // 封面图(文章, 话题)
		"tags":[tag], // 分类标签
		"images":[post], // 下属图片(仅评论, 回答, 讨论, 日记)
		"audios":[post], // 下属音频(仅活动, 话题, 日记)
		"videos":[post], // 下属音频(仅活动, 日记)
		"latest_questions":[post], // 最新的几个问题(仅活动)
		"latest_comments":[post], // 最新的几个回答(仅活动)
		"specialist_answer":post, // 专家解答
		"same_post_comments": [], // 同一个上级内容下回复对象相同的所有评论
		"same_user_comments": [], // 同一个上级内容下同一个用户的所有评论

        "parent":post, // 评论的讨论/文章/回答, 回答的问题, 问题的活动, 讨论的话题, 趣味证书的模板
        "reply_to":post, // 回复的内容(通常指评论回复的评论)
        "related_posts":[post], // 相关内容(仅文章)
        
        "metas":[
            {"key":"", "value": ""}
        ]
    }

### 文章

    {
        "id":"",
        "type":"article",
        "title":"" // 标题 
        "abbreviation":"" // 编辑标题, 较标题更短
        "author":user, // 作者
        "excerpt", // 摘要, 活动介绍, 课程副标题
        "content":"", // 详情
        
        /*以下字段可排序*/
        "views":"0", // 阅读数(文章, 问题)
        "likes":"0", // 点赞数(活动, 文章, 问题, 解答, 评论)
        "reposts":"0", // 转发数(活动, 文章, 问题, 解答, 评论)
        "comments_count":"0", // 问题数(活动), 回答数(问题), 评论数(文章, 解答),
        "favored_users_count":"0" //
        "weight":0, // 权重
        "created_at":"2015-01-01 00:00:00", // 发布时间
        "updated_at":"2015-01-01 00:00:00", // 更新时间
        
        "created_at_human":"刚刚", // 友好显示的发布时间
        "updated_at_human":"刚刚", // 友好显示的更新时间
        
        "liked":false, // 已点赞(若用户未登陆, 为false),
        "favored":"", // 已收藏
        "membership":"", // 权限会员等级(10, 20, 30)
        "membership_label", // 权限会员等级名称(金卡, 白金, 黑金)
        
        "poster":post, // 封面图(文章, 话题)
        "tags":[tag], // 分类标签

        "parent":post,
        "related_posts":[post],
    }

## User (用户)

    {
    	"id":"",
    	"name":"",
    	"realname":"",
		"gender":"", // "男", "女", "未知"
        "mobile":"", // 用户的联系方式（手机）, 默认隐藏
		"address":"", // 地址, 默认隐藏
		"avatar":"",
        "roles":[],
		"level": 1,
		"membership":"", // 会员等级(10, 20, 30)
		"membership_label":"", // 会员等级名称(金卡, 黄金, 黑金)
        "badges":[{"name":"", "value":""}], // 徽章, 其中name为徽章名, value为徽章相关值
        "host_event":post, // 主持的活动
        "subscribed_tags":[""], // 订阅的分类
        "is_specialist":0,
        "professional_field":"", // 专业领域
        "biography":"", // 专家介绍
    	"wx_unionid":"", // 用于标识微信用户
        
        "points":0,
        "following_users_count",
        "followed_users_count",
        "followed":false,
        "created_at":"", // 注册时间
        "last_active_at":"", // 活跃时间, 默认隐藏
        "last_check_message_at":"", // 检查消息时间, 默认隐藏
        
        "created_at_human":"刚刚", // 友好显示的注册时间
        "last_active_at_human":"刚刚", // 友好显示的活跃时间, 默认隐藏
        "last_check_message_at_human":"刚刚", // 友好显示的检查消息时间, 默认隐藏

        /*以下字段仅"获得用户详情"接口*/
		"profiles":[
			{
				"id":"",
				"key":"",
				"value":"",
				"visibility":"" // public:公开显示, protected:公开但不显示, private:自己可见, system:系统内部
			}
		],
		"liked_posts":[post],
		"following_users":[user],
		"followed_users":[user],
		
		"is_anonymous":true, // 匿名作者, 仅当作为post.author且post为匿名发布时才有此属性
		"magic_group":"", // 锦囊分组, 如"0-2岁"
		"magic_credit":2 可免费领取锦囊数
    }

## Message (消息)

	{
		"id":"",
		"user":user,
		"content":"",
		"type":"",
		"event":"",
		"is_read":false,
		"created_at":"",
		"created_at_human":""
	}

## Tag (标签/话题/分类)

	{
		"id":"",
		"name":"",
		"priority":0,
		"weight":0
	}

## Order (订单)

	{
		"id":"",
		"posts":[post],
		"user":user,
		"status":"",
		"price":0 // 订单总价,单位为分人民币,
		"gateway":{
			"name":"weixinpay",
			"app_id":"",
			"timestamp":1400000000,
			"nonce_str":"",
			"package":"",
			"sign_type":"MD5",
			"pay_sign":"",
			"payment_confirmation":{} // 支付确认信息
		}
	}


# 接口

## 前缀

产品: 根据配置文件 API_PREFIX

测试: 根据配置文件 API_PREFIX

开发: http://localhost:8000/api

## 获得聚合信息

	GET /
	response:{
		"articles":[post], // 推荐的文章4篇
		"events":[post], // 推荐的活动4个
		"topics":[post], // 推荐的话题1个
		"tags":[tag], // 推荐的内容分类若干个
		"tags_recent_search":[tag] // 最近热搜的标签, 附有最近搜索数recent_searches
	}

## 发布内容

    POST /post
    body:post
    response:post

post可以包含file键, 此时请求类型须为Content-Type:multipart/form-data

可以包含poster键, poster键中的文件将被创建一个新的poster(封面)类型Post, 并设置为本文封面, 若poster是一个包含id的对象, 那么关联现有Post作为封面

## 获得内容列表

    GET /post
    query:{
    	"id":"",
    	"type":"", // 含义见post.type, 支持逗号分隔"或"搜索
    	"author_id":"",
        "liked_user_id":"", // 根据点赞的用户ID过滤
        "favored_user_id":"", // 根据收藏的用户ID过滤
        "attending_user_id":"", // 根据参与的用户ID过滤
        "attending":true, // 根据当前的用户是否参与过滤
        "paid":true, // 根据当前用户是否有阅读权限过滤
    	"parent_id":"", // 获得活动下的问题; 问题下的解答; 解答, 文章, 话题下的评论
    	"replied":false, // true:有回答, 'specialist':有专家回答, false:无回答
        "per_page":10,
        "page":1,
        "offset":10,
        "limit:20,
        "order_by":"", // 见post可排序字段
        "order":"", // desc, asc, selected (智能精选排序), random(随机排序)
        "keyword":"", // 搜索关键字
		"tag":"", // 标签搜索, 支持逗号分隔"或"搜索
		"visibility":"", // 根据可见性过滤, public或private, 非自己发布的private始终不可见
		"premium":true, // 是否收费内容
		"published":true, // 是否已发布
		"status":"" // 状态
    }
    response:[post]

## 获得内容详情

    GET /post/:id
    response:post

## 更新内容

    PUT /post/:id
    body:post
    response:[post]

## 点赞/分享/参加/收藏

    PUT/PATCH /post/:id
    body:{
        "liked":true,
        "shared":true,
        "attended":true,
        "favored":true,
        "paid":true
    }
    response:[post]

## 删除内容

    DELETE /post/:id

## 获得标签列表

	GET /tag
	query:{
	    "type":"", // 标签类型
	    "related_tag":"", // 包含这个标签的Post的其他标签
	    "post_type":"" // 与related_tag同时使用
	}

## 获得标签

	GET /tag/:id_or_name

## 用户鉴权

    POST /auth/login
    body:{
    	"username":"", // 用户名或手机号
    	"password":""
    }
    response:user // 另含token字段, 包含在以后请求的请求头Authorization中, 用以验证身份
    
## 微信用户鉴权

    GET /wx/:wx_app_name/:code/:scope?
    response:user // 另含token字段, 包含在以后请求的请求头Authorization中, 用以验证身份

## 用户注册/重置密码

    POST /user
    query:{
        "code":""
    }
    body:user
    response:user // 另含token字段, 包含在以后请求的请求头Authorization中, 用以验证身份

## 获得当前用户信息

    GET /auth/user
    response:user

## 获得订单

	GET /order
	query:{
		"status":"",
		"post_id":"",
		"order_by":created_at,status,price
	},
	response:[order]

## 创建订单

	POST /order
	query:{
		"gateway":"weixinpay"
	}
	body:{
		"posts":[posts],
		"contact":""
		"membership":10 // 要购买的会员等级
	},
	response:order
	
## 获取购买会员限额
	
	GET /order/capacity
	
	response: [ capacity : 0]

## 获得消息

	GET /message
	query:{
		"user_id":"",
		"is_read":false,
		"group_by":user_id,event // 分组计数
	},
	response:[message]

## 更新消息

	PUT /message/:id
	body:{
		"is_read":false
	},
	response:message

## 更新多条消息

	PUT /message
	query:{
		"user_id":"",
		"event":""
	},
	body:{
		"id_read":true
	}
	response:[message]

## 修改用户资料和密码

    PUT /auth/user
    query:{
        "code":"" // 更改手机号时需要验证码
    }
    body:user,	// 包含avatar属性时, 请求的Content-Type:multipart/form-data
				// 可以根据上述流程包含password属性来重置密码
    }
    response:user

## 获得手机验证码

	GET /code/:mobile

## 验证手机验证码

	POST /code/:mobile
	body:{
		"code":""
	}
	response:"" // 成功时状态码为200, 失败时状态码为403

## 获得用户列表

    GET /user
    query:{
        "followed_user_id":"", // 获得关注用户
        "following_user_id":"", // 获得粉丝用户
        "liked_post_id":"", // 点赞某个Post的用户
        "shared_post_id":"", // 分享某个Post的用户
        "attending_event_id":"", // 参加某个活动的用户
        "keyword":"", // 表达式搜索
        "profile_key":"", // 包含某个Profile key的用户
        "profile_value":"" // 与profile_key共同使用, Profile为特定值的用户 
    }
    response:[user]

## 更新用户资料, 关注用户和取消

    PUT /user/:user_id
	body:user
    // avatar字段内容是头像图片文件, 此时请求的Content-Type:multipart/form-data
    // followed字段表示用户对此用户关注, 取消关注

## 获得微信号信息

	GET /wx/account
	response:{
		"name":"", // 微信号的代号
		"app_id":"",
		"hostname":"", // 微信号的授权域名 
	}

## 获得网络所在地
	
	GET /ip-location
	response:{
		"province":""
		"city":""
	}

## 获得接口版本

    GET /version
    response:'1.0.0'