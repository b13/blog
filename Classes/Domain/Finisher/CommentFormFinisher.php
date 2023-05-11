<?php
declare(strict_types = 1);

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\Domain\Finisher;

use T3G\AgencyPack\Blog\Domain\Model\Comment;
use T3G\AgencyPack\Blog\Domain\Repository\PostRepository;
use T3G\AgencyPack\Blog\Notification\CommentAddedNotification;
use T3G\AgencyPack\Blog\Notification\NotificationManager;
use T3G\AgencyPack\Blog\Service\CacheService;
use T3G\AgencyPack\Blog\Service\CommentService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ExtensionService;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * This finisher redirects to another Controller.
 *
 * Scope: frontend
 */
class CommentFormFinisher extends AbstractFinisher
{
    protected FlashMessageService $flashMessageService;
    protected PostRepository $postRepository;
    protected CacheService $cacheService;
    protected CommentService $commentService;
    protected TypoScriptService $typoScriptService;
    protected ExtensionService $extensionService;

    public function injectFlashMessageService(FlashMessageService $flashMessageService): void
    {
        $this->flashMessageService = $flashMessageService;
    }

    public function injectPostRepository(PostRepository $postRepository): void
    {
        $this->postRepository = $postRepository;
    }

    public function injectCacheService(CacheService $cacheService): void
    {
        $this->cacheService = $cacheService;
    }

    public function injectCommentService(CommentService $commentService): void
    {
        $this->commentService = $commentService;
    }

    public function injectTypoScriptService(TypoScriptService $typoScriptService): void
    {
        $this->typoScriptService = $typoScriptService;
    }

    public function injectExtensionService(ExtensionService $extensionService): void
    {
        $this->extensionService = $extensionService;
    }

    protected static $messages = [
        CommentService::STATE_ERROR => [
            'title' => 'message.addComment.error.title',
            'text' => 'message.addComment.error.text',
            'severity' => \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR,
        ],
        CommentService::STATE_MODERATION => [
            'title' => 'message.addComment.moderation.title',
            'text' => 'message.addComment.moderation.text',
            'severity' => \TYPO3\CMS\Core\Messaging\AbstractMessage::INFO,
        ],
        CommentService::STATE_SUCCESS => [
            'title' => 'message.addComment.success.title',
            'text' => 'message.addComment.success.text',
            'severity' => \TYPO3\CMS\Core\Messaging\AbstractMessage::OK,
        ],
    ];

    protected function executeInternal()
    {
        $settings = [];
        $frontendController = $this->getTypoScriptFrontendController();
        if ($frontendController instanceof TypoScriptFrontendController) {
            $settings = $frontendController->tmpl->setup['plugin.']['tx_blog.']['settings.'] ?? [];
            $settings = $this->typoScriptService->convertTypoScriptArrayToPlainArray($settings);
        }
        $this->commentService->injectSettings($settings['comments']);

        // Create Comment
        $values = $this->finisherContext->getFormValues();
        $comment = new Comment();
        $comment->setName($values['name'] ?? '');
        $comment->setEmail($values['email'] ?? '');
        $comment->setUrl($values['url'] ?? '');
        $comment->setComment($values['comment'] ?? '');
        $post = $this->postRepository->findCurrentPost();
        $state = $this->commentService->addComment($post, $comment);

        // Add FlashMessage
        $pluginNamespace = $this->extensionService->getPluginNamespace(
            $this->finisherContext->getRequest()->getControllerExtensionName(),
            $this->finisherContext->getRequest()->getPluginName()
        );
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            LocalizationUtility::translate(self::$messages[$state]['text'], 'blog'),
            LocalizationUtility::translate(self::$messages[$state]['title'], 'blog'),
            self::$messages[$state]['severity'],
            true
        );
        $this->flashMessageService->getMessageQueueByIdentifier('extbase.flashmessages.' . $pluginNamespace)->addMessage($flashMessage);

        if ($state !== CommentService::STATE_ERROR) {
            $comment->setCrdate(new \DateTime());

            GeneralUtility::makeInstance(NotificationManager::class)
                ->notify(GeneralUtility::makeInstance(CommentAddedNotification::class, '', '', [
                    'comment' => $comment,
                    'post' => $post,
                ]));
            $this->cacheService->flushCacheByTag('tx_blog_post_' . $post->getUid());
        }
    }

    protected function getTypoScriptFrontendController(): ?TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'];
    }
}
