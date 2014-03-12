<?php
/**
 * Created by PhpStorm.
 * User: aly
 * Date: 3/5/14
 * Time: 11:58 AM
 */

namespace Rephp\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Stream\WritableStreamInterface;

/** @event full-drain */
class Buffer extends EventEmitter implements WritableStreamInterface
{

    public $stream;
    public $listening = false;
    public $softLimit = 2048;
    protected $socket;
    private $writable = true;
    private $loop;
    private $data = '';
    private $lastError = array(
        'number' => 0,
        'message' => '',
        'file' => '',
        'line' => 0,
    );
    private $meta;

    public function __construct(Socket $socket, LoopInterface $loop)
    {
        $this->socket = $socket;
        $this->stream = $socket->getRaw();

        $this->loop = $loop;

        if (is_resource($this->stream)) {
            $this->meta = stream_get_meta_data($this->stream);
        }
    }

    public function isWritable()
    {
        return $this->writable;
    }

    public function write($data)
    {
        if ($this->writable) {
            $this->data .= $data;

            if (!$this->listening) {
                $this->listening = true;

                $this->handleWrite();
            }

            $belowSoftLimit = strlen($this->data) < $this->softLimit;
            return $belowSoftLimit;
        }
    }

    public function end($data = null)
    {
        if (null !== $data) {
            $this->write($data);
        }

        $this->writable = false;

        if ($this->listening) {
            $this->on('full-drain', array($this, 'close'));
        }
        else {
            $this->close();
        }
    }

    public function close()
    {
        $this->writable = false;
        $this->listening = false;
        $this->data = '';

        $this->emit('close', [$this]);
    }

    public function handleWrite()
    {
        if (!is_resource($this->stream) || ('generic_socket' === $this->meta['stream_type'] && feof($this->stream))) {
            $this->emit('error', array(new \RuntimeException('Tried to write to closed or invalid stream.'), $this));
        }
        else{
            set_error_handler(array($this, 'errorHandler'));

            $sent = fwrite($this->stream, $this->data);

            restore_error_handler();

            if (false === $sent) {
                $this->emit(
                    'error',
                    array(
                        new \ErrorException(
                            $this->lastError['message'],
                            0,
                            $this->lastError['number'],
                            $this->lastError['file'],
                            $this->lastError['line']
                        ),
                        $this
                    )
                );


            }
            else{
                $len = strlen($this->data);
                if ($len >= $this->softLimit && $len - $sent < $this->softLimit) {
                    $this->emit('drain', [$this]);
                }

                $this->data = (string)substr($this->data, $sent);

                if (0 === strlen($this->data)) {
                    $this->loop->removeWriteStream($this->stream);
                    $this->listening = false;

                    $this->emit('full-drain', [$this]);
                }
            }
        }

    }

    private function errorHandler($errno, $errstr, $errfile, $errline)
    {
        $this->lastError['number'] = $errno;
        $this->lastError['message'] = $errstr;
        $this->lastError['file'] = $errfile;
        $this->lastError['line'] = $errline;
    }
}
