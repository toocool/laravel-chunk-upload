<?php

namespace BigFileUpload\Laravel\ChunkUpload\Save;

use Illuminate\Http\UploadedFile;
use BigFileUpload\Laravel\ChunkUpload\Config\AbstractConfig;
use BigFileUpload\Laravel\ChunkUpload\Exceptions\ChunkSaveException;
use BigFileUpload\Laravel\ChunkUpload\Exceptions\MissingChunkFilesException;
use BigFileUpload\Laravel\ChunkUpload\FileMerger;
use BigFileUpload\Laravel\ChunkUpload\Handler\AbstractHandler;
use BigFileUpload\Laravel\ChunkUpload\ChunkFile;
use BigFileUpload\Laravel\ChunkUpload\Handler\Traits\HandleParallelUploadTrait;
use BigFileUpload\Laravel\ChunkUpload\Storage\ChunkStorage;

/**
 * Class ParallelSave
 *
 * @method HandleParallelUploadTrait|AbstractHandler handler()
 *
 * @package Pion\Laravel\ChunkUpload\Save
 */
class ParallelSave extends ChunkSave
{
    protected $totalChunks;
    /**
     * Stored on construct - the file is moved and isValid will return false
     * @var bool
     */
    protected $isFileValid;

    /**
     * ParallelSave constructor.
     *
     * @param UploadedFile                              $file         the uploaded file (chunk file)
     * @param AbstractHandler|HandleParallelUploadTrait $handler      the handler that detected the correct save method
     * @param ChunkStorage                              $chunkStorage the chunk storage
     * @param AbstractConfig                            $config       the config manager
     *
     * @throws ChunkSaveException
     */
    public function __construct(
        UploadedFile $file,
        AbstractHandler $handler,
        ChunkStorage $chunkStorage,
        AbstractConfig $config
    )
    {
        // Get current file validation - the file instance is changed
        $this->isFileValid = $file->isValid();

        // Handle the file upload
        parent::__construct($file, $handler, $chunkStorage, $config);
    }

    public function isValid()
    {
        return $this->isFileValid;
    }


    /**
     * Moves the uploaded chunk file to separate chunk file for merging
     *
     * @param string $file Relative path to chunk
     *
     * @return $this
     */
    protected function handleChunkFile($file)
    {
        // Move the uploaded file to chunk folder
        $this->file->move($this->getChunkDirectory(true), $this->chunkFileName);
        return $this;
    }

    protected function tryToBuildFullFileFromChunks()
    {
        return parent::tryToBuildFullFileFromChunks();
    }

    /**
     * Searches for all chunk files
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getSavedChunksFiles()
    {
        $chunkFileName = preg_replace(
            "/\.[\d]+\.".ChunkStorage::CHUNK_EXTENSION."$/", '', $this->handler()->getChunkFileName()
        );
        return $this->chunkStorage->files(function ($file) use ($chunkFileName) {
            return str_contains($file, $chunkFileName) === false;
        });
    }

    /**
     * @throws MissingChunkFilesException
     * @throws ChunkSaveException
     */
    protected function buildFullFileFromChunks()
    {
        $chunkFiles = $this->getSavedChunksFiles()->all();

        if (count($chunkFiles) === 0) {
            throw new MissingChunkFilesException();
        }

        // Sort the chunk order
        natcasesort($chunkFiles);

        // Get chunk files that matches the current chunk file name, also sort the chunk
        // files.
        $finalFilePath = $this->getChunkDirectory(true).'./'.$this->handler()->createChunkFileName();
        // Delete the file if exists
        if (file_exists($finalFilePath)) {
            @unlink($finalFilePath);
        }

        $fileMerger = new FileMerger($finalFilePath);

        // Append each chunk file
        foreach ($chunkFiles as $filePath) {
            // Build the chunk file
            $chunkFile = new ChunkFile($filePath, null, $this->chunkStorage());

            // Append the data
            $fileMerger->appendFile($chunkFile->getAbsolutePath());

            // Delete the chunk file
            $chunkFile->delete();
        }

        $fileMerger->close();

        // Build the chunk file instance
        $this->fullChunkFile = $this->createFullChunkFile($finalFilePath);
    }
}
