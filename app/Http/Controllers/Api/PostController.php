<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePostRequest;
use App\Http\Requests\SearchPostRequest;
use App\Http\Resources\CollectionResource;
use App\Http\Resources\CommentResource;
use App\Http\Resources\PostImagePreviewResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\SongResource;
use App\Http\Service\SearchPostService;
use App\Models\InterestsCategory;
use App\Models\InterestsPostAssigment;
use App\Models\LiveExpirience;
use App\Models\Order;
use App\Models\Post;
use App\Models\PostCollection;
use App\Models\Report;
use App\Models\Song;
use App\Models\User;
use App\Notifications\LikeNotification;
use App\Notifications\NewCommentForPost;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Imagick;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Jobs\ProcessVideoJob;
use App\Jobs\ApplyWatermarkJob;



class PostController extends Controller
{
    private $searchPostService;

    public function __construct(SearchPostService $searchPostService)
    {
        $this->searchPostService = $searchPostService;
    }

    public function index()
    {

        return PostResource::collection(Post::all());
    }

    public function getPost($id)
    {
        try {
            $post = Post::findOrFail($id);
            return new PostResource($post);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'The post has been deleted',
            ], 404);
        }
    }

    public function fileUploader(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
        ]);

        if ($validator->fails()) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Validation errors occurred.',
                    'errors' => $validator->errors(),
                ],
                422
            );
        }

        $file = $request->file('file');
        $uploadedExtension = strtolower($file->getClientOriginalExtension());
        $uploadedMimeType = $file->getMimeType();
        $originalFileName = $file->getClientOriginalName();

        $allowedImageExtensions = ['jpeg', 'jpg', 'png', 'gif'];
        $allowedVideoExtensions = ['webm', 'mov', 'mp4'];

        $user = auth('api')->user();
        $s3FolderPath = 'post-media';

        if (str_contains($uploadedMimeType, 'image')) {
            if (!in_array($uploadedExtension, $allowedImageExtensions)) {
                $imagick = new Imagick($file->getPathname());

                $imagick->setImageFormat('jpeg');

                $convertedFileName =
                    pathinfo($originalFileName, PATHINFO_FILENAME) . '.jpeg';
                $tempFilePath = storage_path($convertedFileName);
                $imagick->writeImage($tempFilePath);

                $file = new UploadedFile(
                    $tempFilePath,
                    $convertedFileName,
                    'image/jpeg',
                    null,
                    true
                );

                $user
                    ->addMedia($file->getPathname())
                    ->usingFileName($file->getClientOriginalName())
                    ->withCustomProperties(['folder' => $s3FolderPath])
                    ->toMediaCollection('temp', 's3');

                $media = $user->getMedia('temp');
            } else {
                $user->addMediaFromRequest('file')->withCustomProperties(['folder' => $s3FolderPath])
                ->toMediaCollection('temp', 's3');
            }
        } elseif (str_contains($uploadedMimeType, 'video')) {
            if (!in_array($uploadedExtension, $allowedVideoExtensions)) {
                $imagick = new Imagick($file->getPathname());

                $imagick->setImageFormat('mp4');

                $convertedFileName =
                    pathinfo($originalFileName, PATHINFO_FILENAME) . '.mp4';
                $tempFilePath = storage_path($convertedFileName);
                $imagick->writeImage($tempFilePath);

                $file = new UploadedFile(
                    $tempFilePath,
                    $convertedFileName,
                    'video/mp4',
                    null,
                    true
                );

                $user
                    ->addMedia($file->getPathname())
                    ->usingFileName($file->getClientOriginalName())
                    ->withCustomProperties(['folder' => $s3FolderPath])
                    ->toMediaCollection('temp', 's3');
            } else {
                $user->addMediaFromRequest('file')->withCustomProperties(['folder' => $s3FolderPath])
                ->toMediaCollection('temp', 's3');
            }
        }

        $media = $user->getMedia('temp');

        return response()->json([
            'data' => PostImagePreviewResource::collection($media),
        ]);
    }

    public function dropFileByUuid(Request $request)
    {
        $uuid = $request->get('uuid');

        $image = Media::where(['uuid' => $uuid])->first();
        $image->delete();
        $user = auth('api')->user();

        $media = $user->getMedia('temp');

        return response()->json(['data' => PostImagePreviewResource::collection($media)]);
    }

    public function fileUploaderForCollection(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
        ]);

        if ($validator->fails()) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Validation errors occurred.',
                    'errors' => $validator->errors(),
                ],
                422
            );
        }

        $file = $request->file('file');
        $uploadedExtension = strtolower($file->getClientOriginalExtension());
        $uploadedMimeType = $file->getMimeType();
        $originalFileName = $file->getClientOriginalName();

        $allowedImageExtensions = ['jpeg', 'jpg', 'png', 'gif'];
        $allowedVideoExtensions = ['webm', 'mov', 'mp4'];

        $user = auth('api')->user();

        if (str_contains($uploadedMimeType, 'image')) {
            if (!in_array($uploadedExtension, $allowedImageExtensions)) {
                $imagick = new Imagick($file->getPathname());

                $imagick->setImageFormat('jpeg');

                $convertedFileName =
                    pathinfo($originalFileName, PATHINFO_FILENAME) . '.jpeg';
                $tempFilePath = storage_path($convertedFileName);
                $imagick->writeImage($tempFilePath);

                $file = new UploadedFile(
                    $tempFilePath,
                    $convertedFileName,
                    'image/jpeg',
                    null,
                    true
                );

                $user
                    ->addMedia($file->getPathname())
                    ->usingFileName($file->getClientOriginalName())
                    ->toMediaCollection('tempCollection');

                $media = $user->getMedia('tempCollection');
            } else {
                $user->addMediaFromRequest('file')->toMediaCollection('tempCollection');
            }
        } elseif (str_contains($uploadedMimeType, 'video')) {
            if (!in_array($uploadedExtension, $allowedVideoExtensions)) {
                $imagick = new Imagick($file->getPathname());

                $imagick->setImageFormat('mp4');

                $convertedFileName =
                    pathinfo($originalFileName, PATHINFO_FILENAME) . '.mp4';
                $tempFilePath = storage_path($convertedFileName);
                $imagick->writeImage($tempFilePath);

                $file = new UploadedFile(
                    $tempFilePath,
                    $convertedFileName,
                    'video/mp4',
                    null,
                    true
                );

                $user
                    ->addMedia($file->getPathname())
                    ->usingFileName($file->getClientOriginalName())
                    ->toMediaCollection('tempCollection');
            } else {
                $user->addMediaFromRequest('file')->toMediaCollection('tempCollection');
            }
        }

        $media = $user->getMedia('tempCollection');

        return response()->json([
            'data' => PostImagePreviewResource::collection($media),
        ]);
    }

    public function dropFiles()
    {
        $user = auth('api')->user();
        $media = $user->getMedia('temp');

        foreach ($media as $file) {
            $file->delete();
        }

        $user->refresh();

        return response()->json(['data' => $user->getMedia('temp')]);
    }

    public function dropCollectionFiles()
    {
        $user = auth('api')->user();
        $media = $user->getMedia('tempCollection');

        foreach ($media as $file) {
            $file->delete();
        }

        $user->refresh();

        return response()->json(['data' => $user->getMedia('tempCollection')]);
    }

    public function checkProfanityText($text)
    {
        $profanityRoute = env('WEBPURIFY_PROFANITY_ENDPOINT');
        $profanityToken = env('WEBPURIFY_PROFANITY_TOKEN');
        $profanityMethod = 'webpurify.live.return';

        // Construct the full API URL with query parameters
        $apiUrl = "http://api1.webpurify.com/services/rest/?format=json&method=webpurify.live.check&api_key={$profanityToken}&text=" . urlencode($text);

        $response = Http::get($apiUrl);
        $jsonBodyResp = json_decode($response->getBody());
        if (isset($jsonBodyResp->rsp) && isset($jsonBodyResp->rsp->found)) {
            if ($jsonBodyResp->rsp->found > 0) {
                return false; // Profanity found
            } else {
                return true; // No profanity found
            }
        } else {

            return true; // Default return value (no profanity found)
        }
    }

    public function checkImage($imageUrl)
    {
        $imageRoute = env('WEBPURIFY_IMAGE_ENDPOINT');
        $imageToken = env('WEBPURIFY_IMAGE_TOKEN');
        $checkMethod = 'webpurify.aim.imgcheck';

        $result = true;

        $response = Http::get($imageRoute .
            '?format=json&api_key=' .
            $imageToken . '&method=' .
            $checkMethod . '&cats=pornography,csam,weapons,drugs,gestures,underwear,extremism,gore,ocr&imgurl=' .
            $imageUrl);
        $jsonBodyResp = json_decode($response);

        if ($jsonBodyResp->rsp->porn > 25) {
            $result = ['res' => false, 'message' => 'porn ' . $jsonBodyResp->rsp->porn . '%'];
        } elseif ($jsonBodyResp->rsp->extremism > 25) {
            $result = ['res' => false, 'message' => 'extremism ' . $jsonBodyResp->rsp->extremism . '%'];
        } elseif ($jsonBodyResp->rsp->underwear > 25) {
            $result = ['res' => false, 'message' => 'underwear ' . $jsonBodyResp->rsp->underwear . '%'];
        } elseif ($jsonBodyResp->rsp->csam > 25) {
            $result = ['res' => false, 'message' => 'csam ' . $jsonBodyResp->rsp->csam . '%'];
        } elseif ($jsonBodyResp->rsp->gesture > 25) {
            $result = ['res' => false, 'message' => 'gesture ' . $jsonBodyResp->rsp->gesture . '%'];
        } elseif ($jsonBodyResp->rsp->gore > 25) {
            $result = ['res' => false, 'message' => 'gore ' . $jsonBodyResp->rsp->gore . '%'];
        } elseif ($jsonBodyResp->rsp->drugs > 25) {
            $result = ['res' => false, 'message' => 'drugs ' . $jsonBodyResp->rsp->drugs . '%'];
        } elseif ($jsonBodyResp->rsp->weapons > 25) {
            $result = ['res' => false, 'message' => 'weapons ' . $jsonBodyResp->rsp->weapons . '%'];
        }

        //        dump($jsonBodyResp);
        return $result;
    }

    public function createPost(CreatePostRequest $request)
    {


        $title = trim($request->get('title'));
        $desc = trim($request->get('description'));

        if (empty($title) || empty($desc)) {
            return response()->json(['error' => 'Title and Description cannot be empty or whitespace.'], 400);
        }
        if (!$request->has('files') || empty($request->get('files'))) {
            return response()->json([
                'error' => "Can't create post",
                'message' => "Post not created! At least one file attachment is required.",
                'status' => 422
            ], 422);
        }

        if ($request->get('id') == 0) {

            $songId = $request->song_id;
            $user = auth('api')->user();

            $request->merge(['owner_id' => $user->id]);

            if (!$request->has('currency')) {
                $request->merge(['currency' => 'usd']);
            }

            if (!$request->has('type')) {
                $request->merge(['type' => 'image']);
            }

            if ($request->song_id) {
                $song = Song::findOrFail($request->song_id);
                $mediaIds = $request->input('files', []);

                foreach ($mediaIds as $mediaId) {
                    $media = Media::where('uuid', $mediaId)->first();

                    if ($media && str_contains($media->mime_type, 'video')) {
                        ProcessVideoJob::dispatch($mediaId, $song->clip_15_sec)->chain([
                            new ApplyWatermarkJob($mediaId , $user->username)
                        ]);
                    }
                }
            } else {
                $request->merge(['song_id' => $songId]);
            }
            if(!$request->song_id){
                $mediaIds = $request->input('files', []);

                foreach ($mediaIds as $mediaId) {
                    $media = Media::where('uuid', $mediaId)->first();

                    if ($media && str_contains($media->mime_type, 'video')) {
                        ApplyWatermarkJob::dispatch($mediaId , $user->username);

                    }
                }
            }
            if ($request->has('shippingIncluded')) {
                $request->merge(['shippingIncluded' => $request->get('shippingIncluded')]);
            } else {
                $request->merge(['shippingIncluded' => 0]);
            }

            if ($request->has('shippingPrice')) {
                $request->merge(['shippingPrice' => (int)$request->get('shippingPrice') * 100]);
            } else {
                $request->merge(['shippingPrice' => 0]);
            }
            if ($request->has('ad')) {
                $request->merge(['ad' => $request->get('ad')]);
            } else {
                $request->merge(['ad' => 0]);
            }

            if ($request->has('time_sale_from_date') || $request->has('time_sale_to_date')) {
                $fromDatePost = \Carbon\Carbon::createFromTimestamp($request->get('time_sale_from_date'))->toDateTimeString();
                $toDatePost = \Carbon\Carbon::createFromTimestamp($request->get('time_sale_to_date'))->toDateTimeString();

                $request->merge(['time_sale_from_date' => $fromDatePost]);
                $request->merge(['time_sale_to_date' => $toDatePost]);
            }

            $isFree = $request->get('isFree', null);

            if ($isFree === true) {
                $request->merge(['fixed_price' => 0, 'post_for_sale' => 0]);
            } elseif ($isFree === false) {
                if ($request->get('fixed_price') <= 0) {
                    return response()->json(
                        [
                            'error' => "Can't create post",
                            'message' => 'Post not created! When isFree is false, fixed_price must be greater than 0.',
                            'status' => 422,
                        ],
                        422
                    );
                } else {
                    $request->merge([
                        'fixed_price' => $request->get('fixed_price') * 100,
                        'post_for_sale' => 1,
                    ]);
                }
            } else {
                // Handle cases where 'isFree' is null or missing from the request
                $request->merge(['post_for_sale' => 0]);
            }

            if ($request->has('fixed_price') && $request->get('fixed_price') > 0) {
                $request->merge(['post_for_sale' => 1]);
                $request->merge(['fixed_price' => request()->get('fixed_price') * 100]);
            } else if ($request->has('fixed_price') && $request->get('fixed_price') == 0) {
                $request->merge(['isFree' => 1]);
                $request->merge(['post_for_sale' => 0]);
            } else {
                $request->merge(['fixed_price' => (int)request()->get('fixed_price') * 100]);
            }
            if ($request->has('isFree') && $request->get('isFree') == true) {
                $request->merge(['post_for_sale' => 1]);
            } else if ($request->has('isFree') && $request->get('isFree') == false) {
                $request->merge(['post_for_sale' => 1]);
            }

            if (!$request->has('isFree') && !$request->has('fixed_price')) {
                $request->merge(['post_for_sale' => 0]);
            }

            if ($request->get('fixed_price') > 0 && $user->paymentAccounts->count() == 0 && $request->get('isFree') == false) {
                return response()->json(['error' => "Can't create post", 'message' => "Post not created! You don't have at least one payment account"], 405);
            }

            if ($request->has('id') && $request->get('id') != 0) {
                $post = Post::where(['id' => $request->get('id')])->first();
                $post->update($request->all());
            } else {
                $post = Post::create($request->all());
            }

            $liveExpiriences = $request->get('liveExperience');

            if ($request->has('id') && $request->get('id') != 0) {
                foreach ($post->intervals as $interval) {
                    $interval->delete();
                }
            }

            //        dd($user->paymentAccounts);


            if (!empty($liveExpiriences)) {
                foreach ($liveExpiriences as $liveExpirience) {

                    $fromDate = \Carbon\Carbon::createFromTimestamp($liveExpirience['startUnixTime'])->toDateTime();
                    $toDate = \Carbon\Carbon::createFromTimestamp($liveExpirience['finalUnixTime'])->toDateTime();


                    $newLiveExpirience = new LiveExpirience();
                    $newLiveExpirience->name = $liveExpirience['content'];
                    if ($liveExpirience['startUnixTime'] == 0) {
                        $newLiveExpirience->startUnixTime = null;
                    } else {
                        $newLiveExpirience->startUnixTime = $fromDate;
                    }

                    if ($liveExpirience['finalUnixTime'] == 0) {
                        $newLiveExpirience->finalUnixTime = null;
                    } else {
                        $newLiveExpirience->finalUnixTime = $toDate;
                    }
                    $newLiveExpirience->post_id = $post->id;
                    $newLiveExpirience->save();
                }
            }

            $post->save();

            $media = $user->getMedia('temp');

            if ($user->hasMedia('temp')) {
                foreach ($media as $file) {
                    try {
                        // Check if the file exists before proceeding
                        if (!$file->exists()) {
                            throw new \Illuminate\Contracts\Filesystem\FileNotFoundException(
                                "File not found: {$file->getPath()}"
                            );
                        }
                        $existingMedia = $user->getMedia('temp')->first();

                        if ($existingMedia) {
                            // Update the existing media attributes
                            $existingMedia->update([
                                'model_id' => $post->id, // Update the model_id to the post's ID
                                'collection_name' => 'files', // Ensure the collection name is set
                                'model_type' => "App\Models\Post", // Optionally update the mime type if necessary
                            ]);
                        }
                        $imgUrl = $file->getUrl();

                        if (!$user->verify) {
                            if (str_contains($file->mime_type, 'video')) {
                                // TODO: Change to check video profanity
                                $result = true;
                            } else {
                                $result = $this->checkImage($imgUrl);
                            }
                            $post->save();
                            if (is_array($result) && isset($result['res'])) {
                                if ($result['res']) {
                                    continue;
                                } else {
                                    $report = new Report();
                                    $report->reason =
                                        'Reported by webpurify image. Post ID ' .
                                        $post->id .
                                        ' | ' .
                                        $result['message'];
                                    $report->status = 'flagged';
                                    $report->reporter_id = 1;
                                    $report->model = 'post';
                                    $report->model_id = $post->id;
                                    $report->save();
                                }
                            } else {
                                // Log an unexpected result format
                                Log::error(
                                    'Unexpected result format from checkImage',
                                    ['result' => $result]
                                );
                            }
                        }
                    } catch (\Illuminate\Contracts\Filesystem\FileNotFoundException $e) {
                        Log::error('File not found', [
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    } catch (\Exception $e) {
                        Log::error(
                            'An error occurred while processing the file',
                            ['error' => $e->getMessage()]
                        );
                        // Handle other exceptions
                        continue;
                    }
                }
            }


            //        if (count($user->collections) == 0) {
            //            $collection = new PostCollection();
            //            $collection->name = $post->title;
            //            $collection->description = $post->description;
            //            $collection->allowToComment = 1;
            //            $collection->allowToCrown = 1;
            //            $collection->user_id = $user->id;
            //            $collection->save();
            //            foreach ($media as $file) {
            //                $file->move($collection, 'files');
            //            }
            //        } else {
            //            $collection = $user->collections->first();
            //        }
            //
            //        $post->collection_id = $collection->id;
            if (!$user->verify) {
                $profanityCheck = $this->checkProfanityText($post->title . ' ' . $post->description);
                //            $profanityImageCheck = $this->checkImage()
                $post->save();

                if ($profanityCheck) {
                } else {
                    $report = new Report();
                    $report->reason = 'Reported by webpurify text. Post ID ' . $post->id;
                    $report->status = 'flagged';
                    $report->reporter_id = 1;
                    $report->model = 'post';
                    $report->model_id = $post->id;
                    $report->save();
                }
            }


            // $user->clearMediaCollection('temp');


            $interests = $request->get('interests');

            if (is_array($interests)) {
                foreach ($interests as $interest) {
                    if ($interest) {
                        $findInterest = InterestsCategory::where(['name' => $interest])->first();
                        $post->assignInterest($findInterest->id);
                    }
                }
            }

            //        if ($request->file('image')) {
            //            $post->addMedia($file)->toMediaCollection('images');
            //        }

            return new PostResource($post);
        } else {
            return $this->updatePost($request, $request->get('id'));
        }
    }

    public function createCollection(Request $request)
    {
        $user = auth('api')->user();
        $collection = new PostCollection();
        $collection->name = $request->get('title');
        $collection->description = $request->get('description');
        $collection->allowToComment = $request->get('allowToComment');
        $collection->allowToCrown = $request->get('allowToCrown');
        $collection->user_id = $user->id;

        $collection->save();

        $media = $user->getMedia('tempCollection');

        if ($user->hasMedia('tempCollection')) {
            foreach ($media as $file) {
                $file->move($collection, 'files');
            }
        }

        $user->clearMediaCollection('tempCollection');

        return response()->json(['data' => CollectionResource::make($collection)]);
    }

    public function getCollection($id)
    {
        $collection = PostCollection::where(['id' => $id])->first();
        return response()->json(['data' => CollectionResource::make($collection)]);
    }

    public function removeCollection($id)
    {
        $collection = PostCollection::where(['id' => $id])->first();
        if (auth('api')->user()->id == $collection->user_id) {

            return response()->json(['data' => $collection->delete()]);
        } else {
            return response()->json(['data' => 'You are not an owner!']);
        }
    }

    public function updateCollection($id, Request $request)
    {
        $collection = PostCollection::where(['id' => $id])->first();

        if (auth('api')->user()->id == $collection->user_id) {
            $collection->name = $request->get('title');
            $collection->description = $request->get('description');
            $collection->allowToComment = $request->get('allowToComment');
            $collection->allowToCrown = $request->get('allowToCrown');

            $collection->save();

            return response()->json(['data' => CollectionResource::make($collection)]);
        } else {
            return response()->json(['error' => 'Not allowed! You are not owner!']);
        }
    }

    public function listCollectionsByUser($id)
    {
        $user = User::where(['id' => $id])->with(['collections'])->first();

        return response()->json(['data' => CollectionResource::collection($user->collections)]);
    }

    public function listPostsByCollectionId($id)
    {
        $collection = PostCollection::where(['id' => $id])->first();

        return response()->json(['data' => $collection->posts->map(function ($item) {
            return $item->id;
        })]);
    }

    public function updatePost(Request $request, $id)
    {
        $user = auth('api')->user();
        $post = Post::where(['id' => $id])->first();


        $title = trim($post->title);
        $desc = trim($post->description);

        if (empty($title) || empty($desc)) {
            return response()->json(['error' => 'Title and Description cannot be empty or whitespace.'], 400);
        }
        $postHasFiles = $post->hasMedia('files');
        if (!$postHasFiles && (!$request->has('files') || empty($request->get('files')))) {
            return response()->json([
                'error' => "Can't update post",
                'message' => "Post not updated! At least one file attachment is required.",
                'status' => 422
            ], 422);
        }
        //        if (count($user->getMedia('temp')) > 0 ) {
        //            $post->clearMediaCollection('files');
        //        }
        if ($request->get('time_sale_from_date') == 0) {
            $request->merge(['time_sale_from_date' => null]);
        }
        if ($request->get('time_sale_to_date') == 0) {
            $request->merge(['time_sale_to_date' => null]);
        }

        if ($request->has('fixed_price') && $request->get('fixed_price') > 0) {
            $request->merge(['fixed_price' => (int)request()->get('fixed_price') * 100]);
        }

        $post->update($request->all());

        $media = $user->getMedia('temp');

        if ($user->hasMedia('temp')) {
            foreach ($media as $file) {
                $file->copy($post, 'files');
                $imgUrl = $file->getUrl();
                if (!$user->verify) {
                    if (str_contains($file->mime_type, 'video')) {
                        //                        TODO Change to check video profanity
                        $result = true;
                    } else {
                        $result = $this->checkImage($imgUrl);
                    }
                    //                    dd($result);
                    $post->save();
                    if ($result['res']) {
                    } else {
                        $report = new Report();
                        $report->reason = 'Reported by webpurify image. Post ID ' . $post->id . ' | ' . $result['message'];
                        $report->status = 'flagged';
                        $report->reporter_id = 1;
                        $report->model = 'post';
                        $report->model_id = $post->id;
                        $report->save();
                    }
                }
            }
        }
        $user->clearMediaCollection('temp');


        $interests = $request->get('interests');

        foreach ($post->interests as $postInterest) {
            $interestPostAssignment = InterestsPostAssigment::where(['post_id' => $post->id, 'interest_id' => $postInterest->id])->first();
            $interestPostAssignment->delete();
        }

        if (is_array($interests)) {
            foreach ($interests as $interest) {
                if ($interest) {
                    $findInterest = InterestsCategory::where(['name' => $interest])->first();
                    $post->assignInterest($findInterest->id);
                }
            }
        }

        $post->refresh();

        return new PostResource($post);
    }

    public function searchPostsByTag(Request $request)
    {
        $tag = $request->get('tag');

        return PostResource::collection(Post::withAnyTags([$tag])->limit(100)->get());
    }

    public function searchPostsByInterest(Request $request)
    {
        $tag = $request->get('tag');

        $posts = Post::whereHas('interests', function ($query) use ($tag) {
            return $query->where('slug', 'LIKE', $tag);
        })->limit(100)->get();

        if ($posts->isEmpty()) {
            return response()->json(
                [
                    'message' => 'No posts found!',
                    'code' => 404,
                ],
                404
            );
        }

        return PostResource::collection($posts);
    }

    public function deletePost(Post $post)
    {
        $user = auth('api')->user();
        if ($post->owner_id !== $user->id) {
            return response()->json('You are not the post author!');
        }
        $orders = Order::where(['post_id' => $post->id])->get();
        foreach ($orders as $order) {
            $order->delete();
        }
        $post->delete();

        return response()->json('success');
    }

    public function search(SearchPostRequest $request)
    {
        //        return response()->json($request->get('types'));
        return PostResource::collection($this->searchPostService->newFilterPosts($request));
    }

    public function setComment(Post $post, Request $request)
    {
        try {
            $user = auth('api')->user();

            if ($post->owner->hasBlocked($user->id)) {
                return response()->json(['error' => 'You cannot comment on this post because the owner has blocked you.'], 403);
            }

            $comment = $request->get('comment');
            $deepLink = 'EXPOSVRE://postcomment/' . $post->id;

            $notification = new \App\Models\Notification();
            $notification->title = 'commented on your post';
            $notification->description = 'commented on your post';
            $notification->type = 'postcomment';
            $notification->user_id = $post->owner_id;
            $notification->sender_id = $user->id;
            $notification->post_id = $post->id;
            $notification->deep_link = $deepLink;
            $notification->save();
            $post->owner->notify(new NewCommentForPost($user, $comment, $post));

            $post->commentAs($user, $request->get('comment'));
            return response()->json(['data' => $post->comments]);
        } catch (\Exception $e) {
            return response()->json($e->getMessage());
        }
    }

    public function commentCollection($id, Request $request)
    {
        try {
            $user = auth('api')->user();
            $collection = PostCollection::where(['id' => $id])->first();

            $collection->commentAs($user, $request->get('comment'));
            return response()->json(['data' => CommentResource::collection($collection->comments)]);
        } catch (\Exception $e) {
            return response()->json($e->getMessage());
        }
    }

    public function setCollectionByPostIdsArray($id, Request $request)
    {
        $collection = PostCollection::where(['id' => $id])->first();

        foreach ($collection->posts as $post) {
            if ($post->owner_id == auth('api')->user()->id) {
                $post->collection_id = null;
                $post->save();
            } else {
                return response()->json(['error' => 'You are not an owner of post' . $post->id . '!']);
            }
        }

        foreach ($request->get('ids') as $postId) {
            $postFind = Post::where(['id' => $postId])->first();
            if ($postFind->owner_id == auth('api')->user()->id) {
                $postFind->collection_id = $collection->id;
                $postFind->save();
            } else {
                return response()->json(['error' => 'You are not an owner of post' . $postFind->id . '!']);
            }
        }

        $collection->refresh();

        return response()->json(['data' => PostResource::collection($collection->posts)]);
    }

    public function collectionListComments($id)
    {
        $collection = PostCollection::where(['id' => $id])->first();
        return response()->json(['data' => CommentResource::collection($collection->comments)]);
    }

    public function getComments(Post $post)
    {
        return response()->json(['data' => CommentResource::collection($post->comments)]);
    }

    public function sortPostsOrderForDashboard(Request $request)
    {
        $postsIdArray = $request->get('sort');

        foreach ($postsIdArray as $key => $postId) {
            $post = Post::where('id', '=', $postId)->first();
            if ($post->owner_id == auth('api')->user()->id) {
                $post->order_priority = $key;
                $post->save();
            }
        }

        return response()->json(['Ok']);
    }

    public function likePost(Post $post)
    {
        $user = auth('api')->user();

        if ($post->owner->hasBlocked($user->id)) {
            return response()->json(['error' => 'You cannot like this post because the owner has blocked you.'], 403);
        }

        $deepLink = 'EXPOSVRE://postlike/' . $post->id;

        $notification = new \App\Models\Notification();
        $notification->title = 'liked your post';
        $notification->description = 'like on your post';
        $notification->type = 'like';
        $notification->user_id = $post->owner_id;
        $notification->sender_id = $user->id;
        $notification->post_id = $post->id;
        $notification->deep_link = $deepLink;
        $notification->save();

        //        dump($user);
        $post->owner->notify(new LikeNotification($user, $post));
        $user->like($post);
        $post->touch();

        //        dump($notification);

        return response()->json(['data' => ['likes' => $post->likers()->count()]]);
    }

    public function likeCollection($id)
    {
        $collection = PostCollection::where(['id' => $id])->first();

        $user = auth('api')->user();
        $user->like($collection);
        $collection->touch();

        return response()->json(['data' => ['crowns' => $collection->likers()->count()]]);
    }

    public function unlikePost(Post $post)
    {
        $user = auth('api')->user();
        $user->unlike($post);
        $post->touch();

        return response()->json(['data' => ['likes' => $post->likers()->count()]]);
    }

    public function unlikeCollection($id)
    {
        $collection = PostCollection::where(['id' => $id])->first();

        $user = auth('api')->user();
        $user->unlike($collection);
        $collection->touch();

        return response()->json(['data' => ['crowns' => $collection->likers()->count()]]);
    }

    public function mostCrowned()
    {
        $user = auth('api')->user();
        $now = Carbon::now();

        // Fetch most crowned posts
        $posts = Post::has('likers')
            ->withCount([
                'likers' => function ($query) {
                    $query->where('likes.created_at', '>=', Carbon::now()->subDays(7));
                },
            ])
            ->orderBy('likers_count', 'DESC')
            ->limit(50)
            ->get();

        $filteredPosts = $posts->filter(function ($post) {
            return $post->reports->count() == 0;
        })->filter(function ($post) use ($now, $user) {
            if ($post->publish_date == null || $post->publish_date <= $now) {
                if ($user->isBlocking($post->owner) || $post->owner->status == 'flagged' || $post->owner->status == 'warning' || $post->owner->status == 'deleted') {
                    return false;
                }
                if ($user->isBlockedBy($post->owner) || $post->owner->status == 'flagged' || $post->owner->status == 'warning' || $post->owner->status == 'deleted') {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }

            return true;
        });

        // Fetch most crowned songs
        $songs = Song::has('likers')
            ->withCount([
                'likers' => function ($query) {
                    $query->where('likes.created_at', '>=', Carbon::now()->subDays(7));
                },
            ])
            ->orderBy('likers_count', 'DESC')
            ->limit(50)
            ->get();

        $formattedPosts = $filteredPosts->map(function ($post) {
            return new PostResource($post);
        })->values();

        $formattedSongs = $songs->map(function ($song) {
            return new SongResource($song);
        })->values();

        return response()->json([
            'data' => [
                'posts' => $formattedPosts,
                'songs' => $formattedSongs,
            ]
        ]);
    }

    public function mostViewed()
    {
        $user = auth('api')->user();
        $now = Carbon::now();

        $sevenDaysAgo = $now->subDays(7);

        $posts = Post::where('updated_at', '>=', $sevenDaysAgo)->where('views_by_last_day', '>', 0)->orderBy('views_by_last_day', 'DESC')
            ->limit(50)
            ->get();


        $filteredPosts = $posts->filter(function ($post) {
            return $post->reports->count() == 0;
        })->filter(function ($post) use ($now, $user) {
            if ($user->isBlocking($post->owner) || $post->publish_date == null || $post->publish_date <= $now) {
                if ($post->owner->status == 'flagged' || $post->owner->status == 'warning' || $post->owner->status == 'deleted') {
                    return false;
                } else {
                    return true;
                }
            } else if ($user->isBlockedBy($post->owner) || $post->publish_date == null || $post->publish_date <= $now) {
                if ($post->owner->status == 'flagged' || $post->owner->status == 'warning' || $post->owner->status == 'deleted') {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }
        });

        $songs = Song::where('updated_at', '>=', $sevenDaysAgo)
            ->where('views_by_last_day', '>', 0)
            ->orderBy('views_by_last_day', 'DESC')
            ->limit(50)
            ->get();

        $formattedPosts = $filteredPosts->map(function ($post) {
            return new PostResource($post);
        })->values();

        $formattedSongs = $songs->map(function ($song) {
            return new SongResource($song);
        })->values();

        return response()->json([
            'data' => [
                'posts' => $formattedPosts,
                'songs' => $formattedSongs,
            ]
        ]);
    }

    public function viewPost($id)
    {
        $post = Post::where(['id' => $id])->first();

        if (!$post) {
            return response()->json(['data' => []], 404);
        }
        $post->views_count = $post->views_count + 1;
        $post->views_by_last_day = $post->views_by_last_day + 1;
        $post->save();

        return response()->json(['data' => new PostResource($post)]);
    }

    public function favoritePost(Post $post)
    {
        $user = auth('api')->user();

        if ($post->owner->hasBlocked($user->id)) {
            return response()->json(['error' => 'You cannot favorite this post because the owner has blocked you.'], 403);
        }
        $user->favorite($post);
        $post->touch();

        return response()->json(['favorites' => $post->favoriters()->count()]);
    }

    public function unfavoritePost(Post $post)
    {
        $user = auth('api')->user();
        $user->unfavorite($post);
        $post->touch();

        return response()->json(['favorites' => $post->favoriters()->count()]);
    }

    public function addToArchive(Post $post)
    {
        $user = auth('api')->user();
        if ($post->owner_id == $user->id) {
            $post->is_archived = true;
            $post->save();

            return response()->json('Ok');
        } else {
            return response()->json('You are not an owner of this post!');
        }
    }

    public function removeFromArchive(Post $post)
    {
        $user = auth('api')->user();
        if ($post->owner_id == $user->id) {
            $post->is_archived = false;
            $post->save();

            return response()->json('Ok');
        } else {
            return response()->json('You are not an owner of this post!');
        }
    }

    public function repost(Post $post)
    {
        $newPost = $post->replicate();
        $newPost->parent_id = $post->id;
        $newPost->created_at = Carbon::now();
        $newPost->likes_count = 0;
        $newPost->views_count = 0;
        $newPost->owner_id = auth('api')->user()->id;
        $newPost->save();

        return new PostResource($newPost);
    }

    public function watermark(){
        $mediaId = '9dd0e26a-3ec5-4d1c-a384-b9cbba5589a4';
        $userName = 'EXPOSVRE';
        ApplyWatermarkJob::dispatch($mediaId , $userName);

    }
}
