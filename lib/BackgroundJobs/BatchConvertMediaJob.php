<?php

namespace OCA\WorkflowMediaConverter\BackgroundJobs;

use OCA\WorkflowMediaConverter\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class BatchConvertMediaJob extends QueuedJob
{
    private LoggerInterface $logger;
    private IRootFolder $rootFolder;
    private IJobList $jobList;
    private ConfigService $configService;

    /** @var Node[] */ private array $unconvertedMedia = [];

    public function __construct(ITimeFactory $time, LoggerInterface $logger, IRootFolder $rootFolder, IJobList $jobList, ConfigService $configService)
    {
        parent::__construct($time);
        $this->logger = $logger;
        $this->rootFolder = $rootFolder;
        $this->jobList = $jobList;
        $this->configService = $configService;
    }

    protected function run($arguments)
    {
        try {
            $this
                ->parseArguments($arguments)
                ->setStatus('seeking')
                ->findUnconvertedMedia()
                ->queueUnconvertedMediaForConversion()
                ->setStatus('converting');
        } catch (\Throwable $e) {
            $this->logger->error("({$e->getCode()}) :: {$e->getMessage()} :: {$e->getTraceAsString()}");
        } finally {
            $this->logger->info(ConvertMedia::class . ' finished');
        }
    }

    private function setStatus($status)
    {
        $batches = $this->configService->getConfigValueJson('batches');

        $index = array_search($this->batchId, array_column($batches, 'id'));

        if (isset($batches[$index])) {
            $batches[$index]['status'] = $status;
        }

        $this->configService->setAppConfigValueJson('batches', $batches);

        return $this;
    }

    private function parseArguments($arguments)
    {
        $this->configService->setUserId($arguments['user_id']);
        $this->batchId = $arguments['id'];
        $this->status = $arguments['status'];
        $this->sourceFolderPath = $arguments['sourceFolder'];
        $this->convertMediaInSubFolders = $arguments['convertMediaInSubFolders'];
        $this->sourceExtension = $arguments['sourceExtension'];
        $this->outputExtension = $arguments['outputExtension'];
        $this->postConversionSourceRule = $arguments['postConversionSourceRule'];
        $this->postConversionSourceRuleMoveFolder = $arguments['postConversionSourceRuleMoveFolder'];
        $this->postConversionOutputRule = $arguments['postConversionOutputRule'];
        $this->postConversionOutputRuleMoveFolder = $arguments['postConversionOutputRuleMoveFolder'];
        $this->postConversionOutputConflictRule = $arguments['postConversionOutputConflictRule'];
        $this->postConversionOutputConflictRuleMoveFolder = $arguments['postConversionOutputConflictRuleMoveFolder'];

        $this->sourceFolder = $this->rootFolder->get($this->sourceFolderPath);

        return $this;
    }

    private function findUnconvertedMedia()
    {
        $this->findUnconvertedMediaInFolder($this->sourceFolder);

        return $this;
    }

    private function findUnconvertedMediaInFolder(Folder $folder)
    {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($this->convertMediaInSubFolders && $node instanceof Folder) {
                $this->findUnconvertedMediaInFolder($node);
            }

            if (!($node instanceof File)) {
                continue;
            }

            $filename = $node->getName();
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            if ($extension !== $this->sourceExtension) {
                continue;
            }

            $filenameNoExtension = str_replace(".{$extension}", '', $filename);
            $possibleOutputFilename = $filenameNoExtension . ".{$this->outputExtension}";

            if (!$folder->nodeExists($possibleOutputFilename)) {
                $this->unconvertedMedia[] = $node;
            }
        }

        return $this;
    }

    private function queueUnconvertedMediaForConversion()
    {
        $this->configService->updateBatch($this->batchId, [
            'unconverted' => count($this->unconvertedMedia)
        ]);

        foreach ($this->unconvertedMedia as $node) {
            $this->jobList->add(ConvertMediaJob::class, [
                'user_id' => $this->userId,
                'batch_id' => $this->batchId,
                'path' => $node->getPath(),
                'outputExtension' => $this->outputExtension,
                'postConversionSourceRule' => $this->postConversionSourceRule,
                'postConversionSourceRuleMoveFolder' => $this->postConversionSourceRuleMoveFolder,
                'postConversionOutputRule' => $this->postConversionOutputRule,
                'postConversionOutputRuleMoveFolder' => $this->postConversionOutputRuleMoveFolder,
                'postConversionOutputConflictRule' => $this->postConversionOutputConflictRule,
                'postConversionOutputConflictRuleMoveFolder' => $this->postConversionOutputConflictRuleMoveFolder
            ]);
        }

        return $this;
    }
}
