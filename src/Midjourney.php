<?php

namespace Ferranfg\MidjourneyPhp;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Midjourney
{

    private const API_URL = 'https://discord.com/api/v9';

    protected const APPLICATION_ID = '936929561302675456';

    protected const DATA_ID = '938956540159881230';

    protected const DATA_VERSION = '1118961510123847772';

    protected const SESSION_ID = '2fb980f65e5c9a77c96ca01f2c242cf6';

    private static PendingRequest $client;

    private static int $channel_id;

    private static string $oauth_token;

    private static $guild_id;

    private static $user_id;

    /**
     *
     * @param $channel_id
     * @param $oauth_token
     * @param string|null $proxy
     * @throws Exception
     */
    public function __construct($channel_id, $oauth_token, string $proxy = null)
    {

        self::$channel_id = $channel_id;
        self::$oauth_token = $oauth_token;

        self::$client = Http::timeout(10)->withHeaders([
            'Authorization' => self::$oauth_token
        ])->baseUrl($proxy ?? self::API_URL);

        self::$guild_id = $this->getGuildId($channel_id);
        self::$user_id = $this->getUserId();
    }

    /**
     * @throws Exception
     */
    public function getGuildId(int $channelId)
    {
        return cache()->remember('dc_guild', 36000, function () use ($channelId) {
            $id = self::$client->get('channels/' . $channelId)->json('guild_id');
            if (empty($id)) throw new Exception('guild_id get failed');
        });
    }

    /**
     * @throws Exception
     */
    public function getUserId()
    {
        return cache()->remember('dc_user', 36000, function () {
            $id = self::$client->get('users/@me')->json('id');
            if (empty($id)) throw new Exception('user_id get failed');
        });
    }

    public function clearCache(): void
    {
        Cache::forget('dc_guild');
        Cache::forget('dc_user');
    }

    private static function firstWhere($array, $key, $value = null)
    {
        foreach ($array as $item) {
            if (
                (is_callable($key) and $key($item)) or
                (is_string($key) and str_starts_with($item[$key], $value))
            ) {
                return $item;
            }
        }

        return null;
    }

    /**
     */
    public function imagine(string $prompt): void
    {
        $params = [
            'type' => 2,
            'application_id' => self::APPLICATION_ID,
            'guild_id' => self::$guild_id,
            'channel_id' => self::$channel_id,
            'session_id' => self::SESSION_ID,
            'data' => [
                'version' => self::DATA_VERSION,
                'id' => self::DATA_ID,
                'name' => 'imagine',
                'type' => 1,
                'options' => [[
                    'type' => 3,
                    'name' => 'prompt',
                    'value' => $prompt
                ]],
                'application_command' => [
                    'id' => self::DATA_ID,
                    'application_id' => self::APPLICATION_ID,
                    'version' => self::DATA_VERSION,
                    'default_member_permissions' => null,
                    'type' => 1,
                    'nsfw' => false,
                    'name' => 'imagine',
                    'description' => 'Create images with Midjourney',
                    'dm_permission' => true,
                    'options' => [[
                        'type' => 3,
                        'name' => 'prompt',
                        'description' => 'The prompt to imagine',
                        'required' => true
                    ]]
                ],
                'attachments' => []
            ]
        ];

        self::$client->post('interactions', $params);
    }

    /**
     */
    public function getImagine(string $prompt): ?array
    {
        $raw_message = self::firstWhere($this->getMessages(self::$channel_id), function ($item) use ($prompt) {
            $content = $item['content'] ?? null;
            if (empty($content)) return null;

            return (
                str_starts_with($content, "**$prompt** - <@" . self::$user_id . '>') and
                !str_contains($content, '%') and
                (str_ends_with($content, '(fast)') || str_ends_with($content, '(relax)'))
            );
        });

        if (is_null($raw_message)) return null;

        return [
            'id' => $raw_message['id'],
            'prompt' => $prompt,
            'raw_message' => $raw_message
        ];
    }

    /**
     * get message list
     *
     */
    public function getMessages(int $channelId, int $limit = 50): array
    {
        return self::$client->get('channels/' . $channelId . '/messages?limit=' . $limit)->json();
    }

    /**
     * @throws Exception
     */
    public function upscale($message, int $upscale_index = 0): void
    {
        if (!($raw_message = $message['raw_message'] ?? null)) {
            throw new Exception('Upscale requires a message object obtained from the imagine/getImagine methods.');
        }

        if ($upscale_index < 0 or $upscale_index > 3) {
            throw new Exception('Upscale index must be between 0 and 3.');
        }

        if (!($components = $raw_message['components'] ?? null)) {
            throw new Exception('components is error');
        }

        $params = [
            'type' => 3,
            'guild_id' => self::$guild_id,
            'channel_id' => self::$channel_id,
            'message_flags' => 0,
            'message_id' => $message->id,
            'application_id' => self::APPLICATION_ID,
            'session_id' => self::SESSION_ID,
            'data' => [
                'component_type' => 2,
                'custom_id' => $components[0]['components'][$upscale_index]['custom_id']
            ]
        ];

        self::$client->post('interactions', $params);
    }

    /**
     * @throws Exception
     */
    public function getUpscale($message, $upscale_index = 0)
    {

        if ($upscale_index < 0 or $upscale_index > 3) {
            throw new Exception('Upscale index must be between 0 and 3.');
        }

        $prompt = $message['prompt'];

        $response = $this->getMessages(self::$channel_id);

        $message_index = $upscale_index + 1;

        $message = self::firstWhere($response, 'content', "**$prompt** - Image #$message_index <@" . self::$user_id . '>');
        if (is_null($message)) {
            $message = self::firstWhere($response, 'content', "**$prompt** - Upscaled by <@" . self::$user_id . '> (fast)');
        }
        if (is_null($message)) {
            $message = self::firstWhere($response, 'content', "**$prompt** - Upscaled by <@" . self::$user_id . '> (relax)');
        }

        if (is_null($message)) return null;

        if (!($attachments = $message['attachments'] ?? null)) {
            return null;
        }
        return $attachments[0]['url'];
    }

    /**
     * @throws Exception
     */
    public function generate($prompt, $upscale_index = 0): array
    {
        $this->imagine($prompt);

        $imagine = null;
        //  todo Maybe endless loop
        while (is_null($imagine)) {
            sleep(10);
            $imagine = $this->getImagine($prompt);
            if (!is_null($imagine)) break;
        }

        $this->upscale($imagine, $upscale_index);

        $upscaled_photo_url = null;
        //  todo Maybe endless loop
        while (is_null($upscaled_photo_url)) {
            sleep(5);
            $upscaled_photo_url = $this->getUpscale($imagine, $upscale_index);
            if (!is_null($upscaled_photo_url)) break;
        }

        return [
            'imagine_message_id' => $imagine['id'],
            'upscaled_photo_url' => $upscaled_photo_url
        ];
    }
}