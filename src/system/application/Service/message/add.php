<?php

namespace svc\message;

trait add
{
    public function add()
    {
        $private_key = $GLOBALS["request"]->private_key;
        $public_keys = $GLOBALS["request"]->public_keys;
        $message = $GLOBALS["request"]->message;

        $sender_public_key = algo_gen_hash($private_key, SLOPT_PRIVATE_KEY_TO_PUBLIC_KEY);
        $sender_public_key_h = algo_gen_hash($sender_public_key, SLOPT_PUBLIC_KEY_DIRNAME);

        if (!is_array($public_keys)) {
            add_message("error", "'public_keys' must be an array of public keys.");
            return json_response();
        } else {
            if (count($public_keys) == 0) {
                add_message("error", "There are no recipients in your submission.");
                return json_response();
            }
            if (count($public_keys) > $max = config("information.multicast_max_receivers")) {
                add_message("error", "Attempting to send messages to more recipients than the network allows ($max).");
                return json_response();
            }
            if (trim($message->content) == "" || trim($message->subject) == "") {
                add_message("error", "Message contents cannot be empty or whitespace.");
                return json_response();
            }
            if (strlen($message->content . $message->subject) >= parse_hsize($size = config("information.message_max_size"))) {
                add_message("error", "Message content cannot be bigger than " . $size . " bytes.");
                return json_response();
            }

            $now = time();
            $id = gen_skyid();
            $id_h = algo_gen_hash($id, SLOPT_SKYID_HASH);
            $message_x = [
                "id" => $id,
                "content" => $message->content,
                "subject" => $message->subject,
                "read" => false,
                "manifest" => [
                    "created_at" => $now,
                    "updated_at" => $now,
                    "is_modified" => false,
                    "message_blake3_digest" => blake3($message->content . $message->subject)
                ],
                "pair" => [
                    "from" => $sender_public_key,
                    "to" => $public_keys
                ]
            ];
            $message_json = json_encode($message_x);

            if (!is_dir($b_path = SENT_PATH . $sender_public_key_h)) {
                mkdir($b_path, 775);
            }

            $message_json_data_for_sender = encrypt_message($message_json, algo_gen_hash($sender_public_key, SLOPT_PUBLIC_KEY_SECRET));
            file_put_contents($b_path . "/" . $id_h, $message_json_data_for_sender);

            $sent = 0;
            foreach ($public_keys as $public_key) {
                $public_key_h = algo_gen_hash($public_key, SLOPT_PUBLIC_KEY_DIRNAME);
                
                if (strcmp($public_key, $sender_public_key) == 0) {
                    add_message("warn", "Sender public key cannot be the same as the target public key. Ignoring this receiver.");
                    continue;
                }

                if (!is_dir($b_path = INBOX_PATH . $public_key_h)) {
                    mkdir($b_path, 775);
                }

                $message_json_data_for_receiver = encrypt_message($message_json, algo_gen_hash($public_key, SLOPT_PUBLIC_KEY_SECRET));
                file_put_contents($b_path . "/" . $id_h, $message_json_data_for_receiver);

                $sent++;
            }
        }

        add_message("info", "Message sent to " . $sent . " public keys");
        return json_response(
            [
                "pair" => [
                    "from" => $sender_public_key,
                    "to" => $public_keys
                ],
                "message_length" => hsize(strlen($message->content . $message->subject)),
                "id" => $id,
                "message_blake3_digest" => blake3($message->content . $message->subject)
            ]
        );
    }
}
