<?php

namespace App\Service;

use App\Cache\LastMessage;
use App\Constants\TalkMessageEvent;
use App\Constants\TalkMessageType;
use App\Model\Group\GroupMember;
use App\Model\Talk\TalkRecordsCode;
use App\Model\Talk\TalkRecordsVote;
use App\Model\Talk\TalkRecordsVoteAnswer;
use App\Support\MessageProducer;
use App\Support\UserRelation;
use Exception;
use App\Constants\MediaFileType;
use App\Model\Talk\TalkRecords;
use App\Model\Talk\TalkRecordsFile;
use Hyperf\DbConnection\Db;

class TalkMessageService
{
    /**
     * 创建代码块消息
     *
     * @param array $message
     * @param array $code
     * @return bool
     */
    public function insertCodeMessage(array $message, array $code)
    {
        Db::beginTransaction();
        try {
            $message['msg_type']   = TalkMessageType::CODE_MESSAGE;
            $message['created_at'] = date('Y-m-d H:i:s');
            $message['updated_at'] = date('Y-m-d H:i:s');

            $insert = TalkRecords::create($message);
            if (!$insert) {
                throw new Exception('插入聊天记录失败...');
            }

            $code['record_id']  = $insert->id;
            $code['created_at'] = date('Y-m-d H:i:s');
            if (!TalkRecordsCode::create($code)) {
                throw new Exception('插入聊天记录(代码消息)失败...');
            }

            Db::commit();
        } catch (Exception $e) {
            Db::rollBack();
            return false;
        }

        MessageProducer::publish(MessageProducer::create(TalkMessageEvent::EVENT_TALK, [
            'sender_id'   => $insert->user_id,
            'receiver_id' => $insert->receiver_id,
            'talk_type'   => $insert->talk_type,
            'record_id'   => $insert->id
        ]));

        LastMessage::getInstance()->save($insert->talk_type, $insert->user_id, $insert->receiver_id, [
            'text'       => '[代码消息]',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /**
     * 创建文件类消息
     *
     * @param array $message
     * @param array $file
     * @return bool
     */
    public function insertFileMessage(array $message, array $file)
    {
        Db::beginTransaction();
        try {
            $message['msg_type']   = TalkMessageType::FILE_MESSAGE;
            $message['created_at'] = date('Y-m-d H:i:s');
            $message['updated_at'] = date('Y-m-d H:i:s');

            $insert = TalkRecords::create($message);
            if (!$insert) {
                throw new Exception('插入聊天记录失败...');
            }

            $file['record_id']  = $insert->id;
            $file['file_type']  = MediaFileType::getMediaType($file['file_suffix']);
            $file['created_at'] = date('Y-m-d H:i:s');
            if (!TalkRecordsFile::create($file)) {
                throw new Exception('插入聊天记录(代码消息)失败...');
            }

            Db::commit();
        } catch (Exception $e) {
            Db::rollBack();
            return false;
        }

        MessageProducer::publish(MessageProducer::create(TalkMessageEvent::EVENT_TALK, [
            'sender_id'   => $insert->user_id,
            'receiver_id' => $insert->receiver_id,
            'talk_type'   => $insert->talk_type,
            'record_id'   => $insert->id
        ]));

        LastMessage::getInstance()->save($insert->talk_type, $insert->user_id, $insert->receiver_id, [
            'text'       => '[图片消息]',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /**
     * 添加投票消息
     *
     * @param array $message
     * @param array $vote
     * @return bool
     */
    public function insertVoteMessage(array $message, array $vote)
    {
        $answer_num = GroupMember::where('group_id', $message['receiver_id'])->where('is_quit', 0)->count();

        Db::beginTransaction();
        try {
            $message['msg_type']   = TalkMessageType::FILE_MESSAGE;
            $message['created_at'] = date('Y-m-d H:i:s');
            $message['updated_at'] = date('Y-m-d H:i:s');

            $insert = TalkRecords::create($message);

            $options = [];
            foreach ($vote['options'] as $k => $option) {
                $options[chr(65 + $k)] = $option;
            }

            $vote['record_id']  = $insert->id;
            $vote['user_id']    = $options;
            $vote['options']    = $options;
            $vote['answer_num'] = $answer_num;
            $vote['created_at'] = date('Y-m-d H:i:s');
            $vote['updated_at'] = $vote['created_at'];
            if (!TalkRecordsVote::create($vote)) {
                throw new Exception('插入聊天记录(投票消息)失败...');
            }

            Db::commit();
        } catch (Exception $e) {
            Db::rollBack();
            return false;
        }

        // 推送消息通知
        MessageProducer::publish(MessageProducer::create(TalkMessageEvent::EVENT_TALK, [
            'sender_id'   => $insert->user_id,
            'receiver_id' => $insert->receiver_id,
            'talk_type'   => $insert->talk_type,
            'record_id'   => $insert->id
        ]));

        LastMessage::getInstance()->save($insert->talk_type, $insert->user_id, $insert->receiver_id, [
            'text'       => '[投票消息]',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /**
     * 群投票处理方法
     *
     * @param int   $user_id
     * @param array $params
     * @return bool
     */
    public function handleVote(int $user_id, array $params): bool
    {
        $record = TalkRecords::join('talk_records_vote as vote', 'vote.record_id', '=', 'talk_records.id')
            ->where('talk_records.id', $params['record_id'])
            ->first([
                'talk_records.id', 'talk_records.receiver_id', 'talk_records.talk_type',
                'vote.id as vote_id', 'vote.answer_mode', 'vote.answer_option', 'vote.answer_num', 'vote.status as vote_status'
            ]);

        if (!$record) return false;

        if ($record->msg_type != TalkMessageType::VOTE_MESSAGE) {
            return false;
        }

        if (!UserRelation::isFriendOrGroupMember($user_id, $record->receiver_id, $record->talk_type)) {
            return false;
        }

        $options = explode(',', $params['options']);
        if (!$options) {
            return false;
        }

        sort($options);

        $answerOption = json_decode($record->answer_option, true);
        foreach ($options as $value) {
            if (!isset($answerOption[$value])) return false;
        }

        // 单选模式取第一个
        if ($record->answer_mode == 1) {
            $options = [$options[0]];
        }

        try {
            Db::transaction(function () use ($options, $record, $user_id) {
                TalkRecordsVote::where('id', $record->vote_id)->update([
                    'answered_num' => Db::raw('answered_num + 1'),
                    'status'       => Db::raw('if(answered_num >= answer_num, 1, 0)'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);

                foreach ($options as $option) {
                    TalkRecordsVoteAnswer::create([
                        'vote_id'    => $record->vote_id,
                        'user_id'    => $user_id,
                        'option'     => $option,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            });
        } catch (\Exception $e) {
            logger()->error($e->getMessage());
            return false;
        }

        return true;
    }
}
