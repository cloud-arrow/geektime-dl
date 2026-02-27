<?php

declare(strict_types=1);

namespace App\M3u8;

use App\Crypto\AesCrypto;
use RuntimeException;

/**
 * MPEG Transport Stream (TS) packet parser with AES-ECB decryption.
 *
 * Ported from Go internal/pkg/m3u8/tsparser.go.
 *
 * Reads 188-byte TS packets, parses headers, extracts PES (Packetized Elementary Stream)
 * fragments for video (PID 0x100) and audio (PID 0x101), then decrypts payload using AES-ECB.
 */
final class TsParser
{
    private const PACKET_LENGTH = 188;

    private const SYNC_BYTE = 0x47;

    private const PAYLOAD_START_MASK = 0x40;

    private const ATF_MASK = 0x30;

    private const ATF_PAYLOAD_ONLY = 0x01;

    private const ATF_FIELD_ONLY = 0x02;

    private const ATF_FIELD_FOLLOW_PAYLOAD = 0x03;

    /**
     * PID for video elementary stream.
     */
    private const PID_VIDEO = 0x100;

    /**
     * PID for audio elementary stream.
     */
    private const PID_AUDIO = 0x101;

    /**
     * Raw TS data (mutable -- decrypted data is written back in-place).
     */
    private string $data;

    /**
     * AES decryption key (raw binary, decoded from hex).
     */
    private string $key;

    /**
     * All parsed TS packets.
     *
     * @var TsPacket[]
     */
    private array $packets = [];

    /**
     * Video PES fragments. Each fragment contains packets for one PES unit.
     *
     * @var TsPesFragment[]
     */
    private array $videos = [];

    /**
     * Audio PES fragments. Each fragment contains packets for one PES unit.
     *
     * @var TsPesFragment[]
     */
    private array $audios = [];

    /**
     * Create a new TsParser from raw TS data and a hex-encoded AES key.
     *
     * @param  string  $data  Raw TS file data
     * @param  string  $hexKey  Hex-encoded AES decryption key
     *
     * @throws RuntimeException If key decoding or TS parsing fails
     */
    public function __construct(string $data, string $hexKey)
    {
        $binaryKey = @hex2bin($hexKey);
        if ($binaryKey === false) {
            throw new RuntimeException('Failed to decode key hex: '.$hexKey);
        }

        $this->data = $data;
        $this->key = $binaryKey;

        $this->parseTs();
    }

    /**
     * Decrypt video and audio PES data using AES-ECB and return the modified TS data.
     *
     * @return string Modified TS data with decrypted PES payloads written back in-place
     *
     * @throws RuntimeException If decryption write-back fails
     */
    public function decrypt(): string
    {
        $this->decryptPes($this->videos);
        $this->decryptPes($this->audios);

        return $this->data;
    }

    /**
     * Decrypt PES fragments using AES-ECB.
     *
     * For each PES fragment:
     * 1. Concatenate all packet payloads
     * 2. AES-ECB decrypt the largest block-aligned portion (length / 16 * 16)
     * 3. Append any remaining unaligned bytes unchanged
     * 4. Write decrypted data back into the original TS data buffer at the correct offsets
     *
     * @param  TsPesFragment[]  $pesFragments
     *
     * @throws RuntimeException If write-back size mismatch occurs
     */
    private function decryptPes(array $pesFragments): void
    {
        foreach ($pesFragments as $pes) {
            // Concatenate all payloads in this PES fragment
            $buffer = '';
            foreach ($pes->packets as $packet) {
                if ($packet->payloadLength === 0 || $packet->payload === '') {
                    continue;
                }
                $buffer .= $packet->payload;
            }

            $length = strlen($buffer);
            if ($length === 0) {
                continue;
            }

            // Decrypt the block-aligned portion using AES-ECB
            $decryptLen = intdiv($length, 16) * 16;
            $result = '';

            if ($decryptLen > 0) {
                $decrypted = AesCrypto::decryptEcb(substr($buffer, 0, $decryptLen), $this->key);
                $result .= $decrypted;
            }

            // Append any remaining bytes (< 16 bytes) unmodified
            if ($decryptLen < $length) {
                $result .= substr($buffer, $decryptLen);
            }

            // Write decrypted bytes back into the main data buffer
            $readerOffset = 0;
            foreach ($pes->packets as $packet) {
                $payloadLen = $packet->payloadLength;
                if ($payloadLen === 0 || $packet->payload === '') {
                    continue;
                }

                $chunk = substr($result, $readerOffset, $payloadLen);
                if (strlen($chunk) !== $payloadLen) {
                    throw new RuntimeException(sprintf(
                        'Decrypt write back failed, expected %d got %d',
                        $payloadLen,
                        strlen($chunk)
                    ));
                }

                // Write back into the main data buffer at the packet's payload start offset
                $this->data = substr_replace(
                    $this->data,
                    $chunk,
                    $packet->payloadStartOffset,
                    $payloadLen
                );

                $readerOffset += $payloadLen;
            }
        }
    }

    /**
     * Parse all TS packets from the raw data.
     *
     * Reads 188-byte packets sequentially, parses headers, and groups packets
     * into video/audio PES fragments based on PID and payload-start indicators.
     *
     * @throws RuntimeException If data length is not a multiple of 188 or sync byte is invalid
     */
    private function parseTs(): void
    {
        $length = strlen($this->data);
        if ($length % self::PACKET_LENGTH !== 0) {
            throw new RuntimeException(sprintf(
                'TS data length %d not multiple of %d',
                $length,
                self::PACKET_LENGTH
            ));
        }

        $numPackets = intdiv($length, self::PACKET_LENGTH);
        $pesVideo = null;
        $pesAudio = null;

        for ($packNo = 0; $packNo < $numPackets; $packNo++) {
            $offset = $packNo * self::PACKET_LENGTH;
            $buffer = substr($this->data, $offset, self::PACKET_LENGTH);

            if (strlen($buffer) < self::PACKET_LENGTH) {
                break; // Incomplete packet at end
            }

            $packet = $this->parseTsPacket($buffer, $packNo, $offset);

            switch ($packet->pid) {
                case self::PID_VIDEO:
                    if ($packet->isPayloadStart) {
                        if ($pesVideo !== null) {
                            $this->videos[] = $pesVideo;
                        }
                        $pesVideo = new TsPesFragment;
                    }
                    $pesVideo?->addPacket($packet);
                    break;

                case self::PID_AUDIO:
                    if ($packet->isPayloadStart) {
                        if ($pesAudio !== null) {
                            $this->audios[] = $pesAudio;
                        }
                        $pesAudio = new TsPesFragment;
                    }
                    $pesAudio?->addPacket($packet);
                    break;
            }

            $this->packets[] = $packet;
        }

        // Flush final PES fragments
        if ($pesVideo !== null) {
            $this->videos[] = $pesVideo;
        }
        if ($pesAudio !== null) {
            $this->audios[] = $pesAudio;
        }
    }

    /**
     * Parse a single 188-byte TS packet.
     *
     * Header layout (4 bytes):
     *   Byte 0: sync byte (0x47)
     *   Byte 1: TEI(1) | PUSI(1) | priority(1) | PID[12:8](5)
     *   Byte 2: PID[7:0](8)
     *   Byte 3: TSC(2) | AFC(2) | CC(4)
     *
     * @param  string  $buffer  188-byte packet data
     * @param  int  $packNo  Packet sequence number
     * @param  int  $offset  Byte offset in the original data
     * @return TsPacket Parsed packet
     *
     * @throws RuntimeException If sync byte is invalid
     */
    private function parseTsPacket(string $buffer, int $packNo, int $offset): TsPacket
    {
        $b0 = ord($buffer[0]);
        if ($b0 !== self::SYNC_BYTE) {
            throw new RuntimeException(sprintf(
                'Invalid TS package at %d offset %d',
                $packNo,
                $offset
            ));
        }

        $b1 = ord($buffer[1]);
        $b2 = ord($buffer[2]);
        $b3 = ord($buffer[3]);

        // Parse header fields using bitwise operations matching Go exactly
        $transportErrorIndicator = ($b1 & 0x80) >> 7;
        $payloadUnitStartIndicator = ($b1 & self::PAYLOAD_START_MASK) >> 6;
        $pid = (($b1 & 0x1F) << 8) | $b2;
        $transportScramblingControl = ($b3 & 0xC0) >> 6;
        $adaptationField = ($b3 & self::ATF_MASK) >> 4;
        $continuityCounter = $b3 & 0x0F;

        $hasError = $transportErrorIndicator !== 0;
        $isPayloadStart = $payloadUnitStartIndicator !== 0;
        $hasAdaptationField = $adaptationField === self::ATF_FIELD_ONLY
            || $adaptationField === self::ATF_FIELD_FOLLOW_PAYLOAD;
        $hasPayload = $adaptationField === self::ATF_PAYLOAD_ONLY
            || $adaptationField === self::ATF_FIELD_FOLLOW_PAYLOAD;

        // Build packet
        $packet = new TsPacket;
        $packet->pid = $pid;
        $packet->packNo = $packNo;
        $packet->startOffset = $offset;
        $packet->isPayloadStart = $isPayloadStart;
        $packet->hasError = $hasError;
        $packet->hasAdaptationField = $hasAdaptationField;
        $packet->hasPayload = $hasPayload;
        $packet->transportScramblingControl = $transportScramblingControl;
        $packet->continuityCounter = $continuityCounter;

        // Header is always 4 bytes
        $headerLength = 4;

        // Adaptation field length
        $atfLength = 0;
        if ($hasAdaptationField) {
            $atfLength = ord($buffer[4]);
            $headerLength += 1 + $atfLength;
        }
        $packet->atfLength = $atfLength;

        // PES header length (only if this is a payload start)
        $pesHeaderLength = 0;
        if ($isPayloadStart) {
            // Check bounds: headerLength + 8 < buffer length
            if ($headerLength + 8 < strlen($buffer)) {
                $pesHeaderLength = 6 + 3 + ord($buffer[$headerLength + 8]);
            }
            $packet->pesOffset = $offset + $headerLength;
        }
        $packet->pesHeaderLength = $pesHeaderLength;
        $packet->headerLength = $headerLength;

        // Calculate payload position and length
        $packet->payloadStartOffset = $offset + $headerLength + $pesHeaderLength;
        $packet->payloadLength = self::PACKET_LENGTH - $headerLength - $pesHeaderLength;

        if ($packet->payloadLength < 0) {
            $packet->payloadLength = 0;
        }

        // Extract payload bytes
        if ($packet->payloadLength > 0) {
            $payloadOffset = $headerLength + $pesHeaderLength;
            $packet->payload = substr($buffer, $payloadOffset);
        } else {
            $packet->payload = '';
        }

        return $packet;
    }
}

/**
 * Represents a single parsed TS packet.
 *
 * @internal
 */
final class TsPacket
{
    public int $pid = 0;

    public int $packNo = 0;

    public int $startOffset = 0;

    public int $headerLength = 4;

    public int $atfLength = 0;

    public int $pesOffset = 0;

    public int $pesHeaderLength = 0;

    public int $payloadStartOffset = 0;

    public int $payloadLength = 0;

    public string $payload = '';

    public bool $isPayloadStart = false;

    public bool $hasError = false;

    public bool $hasAdaptationField = false;

    public bool $hasPayload = false;

    public int $transportScramblingControl = 0;

    public int $continuityCounter = 0;
}

/**
 * A PES (Packetized Elementary Stream) fragment consisting of multiple TS packets.
 *
 * @internal
 */
final class TsPesFragment
{
    /**
     * @var TsPacket[]
     */
    public array $packets = [];

    public function addPacket(TsPacket $packet): void
    {
        $this->packets[] = $packet;
    }
}
