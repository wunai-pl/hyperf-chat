<?php

namespace App\Service;

use App\Cache\LastMessage;
use App\Cache\ServerRunID;
use App\Cache\UnreadTalk;
use App\Constants\TalkMode;
use App\Model\TalkList;
use App\Model\UsersFriend;

class TalkListService
{
    /**
     * 创建聊天列表记录
     *
     * @param int $user_id     用户ID
     * @param int $receiver_id 接收者ID
     * @param int $talk_type   创建类型[1:私聊;2:群聊;]
     * @return array
     */
    public function create(int $user_id, int $receiver_id, int $talk_type)
    {
        $result = TalkList::updateOrCreate([
            'talk_type'   => $talk_type,
            'user_id'     => $user_id,
            'receiver_id' => $receiver_id,
        ], [
            'is_top'     => 0,
            'is_delete'  => 0,
            'is_disturb' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'id'          => $result->id,
            'talk_type'   => $result->talk_type,
            'receiver_id' => $result->receiver_id,
        ];
    }

    /**
     * 聊天对话列表置顶操作
     *
     * @param int  $user_id 用户ID
     * @param int  $list_id 会话列表ID
     * @param bool $is_top  是否置顶（true:是 false:否）
     * @return bool
     */
    public function top(int $user_id, int $list_id, $is_top = true)
    {
        return (bool)TalkList::query()->where([
            ['id', '=', $list_id],
            ['user_id', '=', $user_id],
        ])->update([
            'is_top'     => $is_top ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 删除会话列表
     *
     * @param int $user_id 用户ID
     * @param int $list_id 会话列表ID
     * @return bool
     */
    public function delete(int $user_id, int $list_id)
    {
        return (bool)TalkList::query()->where([
            ['id', '=', $list_id],
            ['user_id', '=', $user_id],
        ])->update([
            'is_delete'  => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 删除会话列表
     *
     * @param int $user_id     用户ID
     * @param int $receiver_id 接受者ID
     * @param int $talk_type   对话类型
     * @return bool
     */
    public function deleteByType(int $user_id, int $receiver_id, int $talk_type)
    {
        return (bool)TalkList::query()->where([
            ['user_id', '=', $user_id],
            ['talk_type', '=', $talk_type],
            ['receiver_id', '=', $receiver_id],
        ])->update([
            'is_delete'  => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 获取用户的聊天列表
     *
     * @param int $user_id 用户ID
     * @return array
     */
    public function getTalkList(int $user_id)
    {
        $filed = [
            'list.id', 'list.talk_type', 'list.receiver_id', 'list.updated_at', 'list.is_disturb', 'list.is_top',
            'users.avatar as user_avatar', 'users.nickname',
            'group.group_name', 'group.avatar as group_avatar'
        ];

        $rows = TalkList::from('talk_list as list')
            ->leftJoin('users', function ($join) {
                $join->on('users.id', '=', 'list.receiver_id')->where('list.talk_type', '=', TalkMode::PRIVATE_CHAT);
            })
            ->leftJoin('group', function ($join) {
                $join->on('group.id', '=', 'list.receiver_id')->where('list.talk_type', '=', TalkMode::GROUP_CHAT);
            })
            ->where('list.user_id', $user_id)
            ->where('list.is_delete', 0)
            ->orderBy('list.updated_at', 'desc')
            ->get($filed)
            ->toArray();

        if (!$rows) return [];

        $socketFDService = make(SocketClientService::class);
        $runIdAll        = ServerRunID::getInstance()->getServerRunIdAll();

        return array_map(function ($item) use ($user_id, $socketFDService, $runIdAll) {
            $data['id']          = $item['id'];
            $data['talk_type']   = $item['talk_type'];
            $data['receiver_id'] = $item['receiver_id'];
            $data['avatar']      = '';     // 默认头像
            $data['name']        = '';     // 对方昵称/群名称
            $data['remark_name'] = '';     // 好友备注
            $data['unread_num']  = 0;      // 未读消息
            $data['is_online']   = false;  // 是否在线
            $data['is_top']      = $item['is_top'];
            $data['is_disturb']  = $item['is_disturb'];
            $data['msg_text']    = '......';
            $data['updated_at']  = $item['updated_at'] ?: '2020-01-01 00:00:00';

            if ($item['talk_type'] == TalkMode::PRIVATE_CHAT) {
                $data['name']        = $item['nickname'];
                $data['avatar']      = $item['user_avatar'];
                $data['unread_num']  = UnreadTalk::getInstance()->read($item['receiver_id'], $user_id);
                $data['is_online']   = $socketFDService->isOnlineAll($item['receiver_id'], $runIdAll);
                $data['remark_name'] = UsersFriend::getFriendRemark($user_id, (int)$item['receiver_id']);
            } else {
                $data['name']   = strval($item['group_name']);
                $data['avatar'] = $item['group_avatar'];
            }

            $records = LastMessage::getInstance()->read($data['talk_type'], $user_id, $data['receiver_id']);
            if ($records) {
                $data['msg_text']   = $records['text'];
                $data['updated_at'] = $records['created_at'];
            }

            return $data;
        }, $rows);
    }

    /**
     * 设置消息免打扰
     *
     * @param int $user_id     用户ID
     * @param int $receiver_id 接收者ID
     * @param int $talk_type   接收者类型[1:好友;2:群组;]
     * @param int $is_disturb  是否免打扰[0:否;1:是;]
     * @return boolean
     */
    public function disturb(int $user_id, int $receiver_id, int $talk_type, int $is_disturb)
    {
        $result = TalkList::query()->where([
            ['user_id', '=', $user_id],
            ['talk_type', '=', $talk_type],
            ['receiver_id', '=', $receiver_id],
        ])->first(['id', 'is_disturb']);

        if (!$result || $is_disturb == $result->is_disturb) {
            return false;
        }

        return (bool)TalkList::query()->where('id', $result->id)->update([
            'is_disturb' => $is_disturb,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
}