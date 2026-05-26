import { useState, useCallback, useEffect, useRef } from 'react';
import { useTranslation } from '@/lib/i18n';
import { useDropzone } from 'react-dropzone';
import axios from 'axios';
import { cn } from '@/lib/utils';

import {
	Dialog,
	DialogContent,
	DialogHeader,
	DialogTitle,
	DialogFooter,
	DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
	Card,
	CardContent,
} from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Badge } from '@/components/ui/badge';
import {
	Upload,
	X,
	CheckCircle,
	AlertCircle,
	File as FileIcon,
	ImageIcon,
	FileTextIcon,
	FileVideoIcon,
	FileAudioIcon,
	StopCircle,
} from 'lucide-react';


interface AssetUploaderProps {
	isOpen: boolean;
	onClose: () => void;
	projectId: number;
	projectUuid: string;
	onUploadComplete: () => void;
}

type UploadStatus = 'pending' | 'uploading' | 'completed' | 'error' | 'cancelled';

interface UploadFile {
	id: string;
	file: File;
	progress: number;
	error: string | null;
	completed: boolean;
	status: UploadStatus;
}

export default function AssetUploader({ isOpen, onClose, projectId, projectUuid, onUploadComplete }: AssetUploaderProps) {
	const t = useTranslation();
	const [files, setFiles] = useState<UploadFile[]>([]);
	const [uploading, setUploading] = useState(false);
	const [overallProgress, setOverallProgress] = useState(0);
	const [currentUploadingId, setCurrentUploadingId] = useState<string | null>(null);

	// Use refs to track state across async operations
	const isStoppedRef = useRef(false);
	const uploadingRef = useRef(false);

	// Refs for scroll functionality
	const fileRefs = useRef<{ [key: string]: HTMLDivElement | null }>({});
	const scrollAreaRef = useRef<HTMLDivElement>(null);

	const onDrop = useCallback((acceptedFiles: File[]) => {
		const newFiles = acceptedFiles.map(file => ({
			id: Math.random().toString(36).substring(2, 9),
			file,
			progress: 0,
			error: null,
			completed: false,
			status: 'pending' as UploadStatus,
		}));

		setFiles(prevFiles => [...prevFiles, ...newFiles]);
	}, []);

	const { getRootProps, getInputProps, isDragActive } = useDropzone({
		onDrop,
		accept: {
			'image/*': [],
			'video/*': [],
			'audio/*': [],
			'application/pdf': [],
			'application/msword': [],
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document': [],
			'application/vnd.ms-excel': [],
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': [],
			'application/vnd.ms-powerpoint': [],
			'application/vnd.openxmlformats-officedocument.presentationml.presentation': [],
			'text/plain': [],
		}
	});

	const removeFile = (id: string) => {
		setFiles(prevFiles => prevFiles.filter(file => file.id !== id));
	};

	const updateFileProgress = (id: string, progress: number) => {
		setFiles(prevFiles => {
			// Create a new array with the updated progress for the specific file
			const updatedFiles = prevFiles.map(file =>
				file.id === id ? { ...file, progress } : file
			);

			// Calculate overall progress based on all files that are being processed
			// Include pending, uploading and completed files in the calculation
			const filesToCount = updatedFiles.filter(f =>
				f.status === 'pending' || f.status === 'uploading' || f.status === 'completed'
			);

			if (filesToCount.length > 0) {
				// Calculate total progress across all files
				const totalProgress = filesToCount.reduce((acc, file) => {
					// Pending files contribute 0% to the total
					if (file.status === 'pending') return acc;
					// Completed files contribute 100% to the total
					if (file.status === 'completed') return acc + 100;
					// Uploading files contribute their current progress
					return acc + file.progress;
				}, 0);

				// Calculate overall progress as percentage
				const calculatedProgress = totalProgress / filesToCount.length;
				setOverallProgress(calculatedProgress);
			}

			return updatedFiles;
		});
	};

	const updateFileStatus = (id: string, status: UploadStatus, error: string | null = null) => {
		setFiles(prevFiles => prevFiles.map(file =>
			file.id === id ? {
				...file,
				status,
				completed: status === 'completed',
				error: error
			} : file
		));
	};

	// Effect to scroll to currently uploading file
	useEffect(() => {
		if (currentUploadingId && fileRefs.current[currentUploadingId] && scrollAreaRef.current) {
			const fileElement = fileRefs.current[currentUploadingId];
			fileElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}, [currentUploadingId]);

	// STOP function
	const stopUploads = () => {
		// Update state and refs
		isStoppedRef.current = true;

		// Cancel all pending uploads
		setFiles(prevFiles =>
			prevFiles.map(file =>
				file.status === 'uploading' || file.status === 'pending'
					? { ...file, status: 'cancelled', error: 'Upload cancelled' }
					: file
			)
		);

		// Reset upload state
		setUploading(false);
		uploadingRef.current = false;
		setCurrentUploadingId(null);
	};

	// Upload files one by one
	const uploadFiles = async () => {
		// If already uploading, don't start another upload process
		if (uploadingRef.current) {
			return;
		}

		// Reset control flags
		isStoppedRef.current = false;

		// Set uploading state
		setUploading(true);
		uploadingRef.current = true;

		try {
			// Get only the pending files
			const pendingFiles = files.filter(file => file.status === 'pending');

			// Process files one by one
			for (let i = 0; i < pendingFiles.length; i++) {
				// Check if we should stop before processing each file
				if (isStoppedRef.current) {
					break;
				}

				const fileItem = pendingFiles[i];

				// Set current uploading file for scroll tracking
				setCurrentUploadingId(fileItem.id);
				updateFileStatus(fileItem.id, 'uploading');

				const formData = new FormData();
				formData.append('file', fileItem.file);

				try {
					// Do the upload
					const response = await axios.post(`/api/projects/${projectUuid}/media`, formData, {
						headers: {
							'Content-Type': 'multipart/form-data',
						},
						onUploadProgress: (progressEvent) => {
							// Skip progress updates if stopped
							if (isStoppedRef.current) return;

							if (progressEvent.total) {
								const progress = Math.round((progressEvent.loaded / progressEvent.total) * 100);
								updateFileProgress(fileItem.id, progress);
							}
						},
					});

					// Check state flags again after upload finishes
					if (isStoppedRef.current) {
						updateFileStatus(fileItem.id, 'cancelled');
						break;
					}

					// Mark file as completed
					updateFileProgress(fileItem.id, 100);
					updateFileStatus(fileItem.id, 'completed');

				} catch (error: any) {
					let errorMessage = t('assets.uploader_failed_default');

					// Extract detailed error message
					if (error.response) {
						if (error.response.data && error.response.data.message) {
							errorMessage = error.response.data.message;

							// Add validation details if available
							if (error.response.data.errors && error.response.data.errors.file) {
								const fileErrors = error.response.data.errors.file;
								if (Array.isArray(fileErrors) && fileErrors.length > 0) {
									errorMessage += `: ${fileErrors.join(', ')}`;
								}
							}

							// Add file size limit info if available
							if (error.response.data.file_size_limit) {
								errorMessage += ` (Max size: ${error.response.data.file_size_limit})`;
							}
						} else if (error.response.status === 413) {
							errorMessage = t('assets.uploader_too_large');
						}
					}

					if (isStoppedRef.current) {
						updateFileStatus(fileItem.id, 'cancelled');
						break;
					} else {
						updateFileStatus(fileItem.id, 'error', errorMessage);
					}
				}
			}
		} finally {
			setUploading(false);
			uploadingRef.current = false;
			setCurrentUploadingId(null);
			onUploadComplete();
		}
	};

	// Effect to reset everything when modal is closed
	useEffect(() => {
		if (!isOpen) {
			setFiles([]);
			setOverallProgress(0);
			setCurrentUploadingId(null);
			isStoppedRef.current = true;
			uploadingRef.current = false;
		}
	}, [isOpen]);

	const handleClose = () => {
		if (uploading) {
			return; // Prevent closing while uploading
		}
		onClose();
	};

	const allCompleted = files.length > 0 && files.every(file =>
		file.status === 'completed' || file.status === 'cancelled' || file.status === 'error'
	);

	const hasActiveUploads = files.some(file =>
		file.status === 'uploading' || file.status === 'pending'
	);

	const getFileIcon = (file: File) => {
		const type = file.type;

		if (type.startsWith('image/')) {
			return <ImageIcon className="h-4 w-4 text-blue-500" />;
		}

		if (type.startsWith('video/')) {
			return <FileVideoIcon className="h-4 w-4 text-purple-500" />;
		}

		if (type.startsWith('audio/')) {
			return <FileAudioIcon className="h-4 w-4 text-green-500" />;
		}

		if (type.includes('pdf') || type.includes('document') || type.includes('text')) {
			return <FileTextIcon className="h-4 w-4 text-amber-500" />;
		}

		return <FileIcon className="h-4 w-4 text-gray-500" />;
	};

	// Format file size to human-readable format
	const formatFileSize = (bytes: number) => {
		if (bytes < 1024) {
			return bytes + ' B';
		} else if (bytes < 1024 * 1024) {
			return (bytes / 1024).toFixed(1) + ' KB';
		} else {
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
		}
	};

	// Get status display text
	const getStatusDisplay = (file: UploadFile) => {
		switch (file.status) {
			case 'uploading':
				return file.progress < 100 ? t('assets.uploader_status_uploading') : t('assets.uploader_status_processing');
			case 'completed':
				return t('assets.uploader_status_completed');
			case 'error':
				return t('assets.uploader_status_failed');
			case 'cancelled':
				return t('assets.uploader_status_cancelled');
			default:
				return t('assets.uploader_status_pending');
		}
	};

	return (
		<Dialog open={isOpen} onOpenChange={handleClose}>
			<DialogContent className="sm:max-w-lg max-h-[90vh] flex flex-col overflow-y-auto">
				<DialogHeader>
					<DialogTitle>{t('assets.uploader_title')}</DialogTitle>
					<DialogDescription>
						{t('assets.uploader_desc')}
					</DialogDescription>
				</DialogHeader>

				{/* Uploader/ hide this when upload starts */}
				{!uploading && (
					<div
						{...getRootProps()}
						className={cn(
							"border-2 border-dashed rounded-lg p-6 text-center cursor-pointer transition-colors mt-4",
							isDragActive
								? "border-primary bg-primary/5"
								: "border-muted-foreground/20 hover:border-muted-foreground/30"
						)}
					>
						<input {...getInputProps()} />
						<Upload className="h-10 w-10 mx-auto text-muted-foreground" />
						<p className="mt-2 text-sm text-muted-foreground font-medium">
							{isDragActive ? t('assets.uploader_drop') : t('assets.uploader_drag')}
						</p>
						<p className="text-xs text-muted-foreground/70 mt-1">
							{t('assets.uploader_types')}
						</p>
					</div>
				)}

				{/* Uploader/ hide this when upload starts */}
				{files.length > 0 && (
					<div className="space-y-3 mt-4">
						<div className="flex justify-between items-center">
							<Badge variant="outline" className="px-2 py-1">
								{t('assets.uploader_files_selected', { count: String(files.length) })}
							</Badge>
							{allCompleted && (
								<Badge variant="outline" className="bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800 px-2 py-1">
									<CheckCircle className="h-3.5 w-3.5 mr-1" />
									<span>{t('assets.uploader_complete')}</span>
								</Badge>
							)}
						</div>

						{uploading && (
							<div className="space-y-1 px-1">
								<div className="flex justify-between items-center text-xs text-muted-foreground">
									<span className="font-medium">
										{overallProgress < 100
											? t('assets.uploader_uploading')
											: t('assets.uploader_processing')}
									</span>
									<span className="tabular-nums font-medium">
										{Math.round(overallProgress)}%
									</span>
								</div>
								<Progress
									value={overallProgress}
									className={cn(
										"h-2",
										overallProgress === 100 && "bg-green-200 dark:bg-green-900/30 [&>div]:bg-green-600 dark:[&>div]:bg-green-500"
									)}
								/>

								{uploading && hasActiveUploads && (
									<div className="flex items-center justify-center gap-2 mt-2">
										<Button
											size="sm"
											variant="outline"
											className="text-xs h-8 text-destructive hover:text-destructive"
											onClick={stopUploads}
										>
											<StopCircle className="h-3.5 w-3.5 mr-1.5" />
											{t('assets.uploader_stop')}
										</Button>
									</div>
								)}
							</div>
						)}

						{files.some(file => file.status === 'error') && (
							<Alert variant="destructive" className="mt-3">
								<AlertDescription>
									{t('assets.uploader_error_desc')}
								</AlertDescription>
							</Alert>
						)}

						{/* File list */}
						<Card className="border-muted-foreground/10 pt-0 pb-0">
							<ScrollArea className="h-[200px] overflow-y-auto" ref={scrollAreaRef}>
								<CardContent className="p-0">
									<div className="divide-y divide-border">
										{files.map(file => (
											<div
												key={file.id}
												ref={(el) => { fileRefs.current[file.id] = el; }}
												className={cn(
													"flex justify-between items-center p-3 transition-colors",
													file.status === 'error' && "bg-destructive/5",
													file.status === 'cancelled' && "bg-slate-50 dark:bg-slate-900/20",
													file.status !== 'completed' && file.status !== 'error' && file.status !== 'cancelled' && "hover:bg-muted/50",
													currentUploadingId === file.id && "bg-blue-50/50 dark:bg-blue-900/30"
												)}
											>
												<div className="w-12">
													{getFileIcon(file.file)}
												</div>

												<div className="flex-1 ml-2 max-w-[260px]">
													<div className="text-sm font-medium truncate" title={file.file.name}>
														{file.file.name}
													</div>
													<div className="text-xs text-muted-foreground flex items-center gap-2">
														<span>{formatFileSize(file.file.size)}</span>
														<span className="text-xs text-muted-foreground/70">
															{file.status}
														</span>
														{file.status === 'error' && (
															<span className="text-destructive flex items-center gap-1">
																<AlertCircle className="h-3 w-3" />
																Error
															</span>
														)}
														{file.status === 'cancelled' && (
															<span className="text-slate-500 flex items-center gap-1">
																<StopCircle className="h-3 w-3" />
																Cancelled
															</span>
														)}
													</div>
													{file.status === 'error' && file.error && (
														<div className="mt-1 text-xs text-destructive">
															{file.error}
														</div>
													)}
												</div>

												<div className="flex items-center w-full justify-end max-w-[120px]">
													{file.status === 'completed' ? (
														<CheckCircle className="h-5 w-5 text-green-600 dark:text-green-500" />
													) : file.status === 'error' || file.status === 'cancelled' ? (
														<Button
															variant="outline"
															size="sm"
															className="h-7 px-2 text-xs"
															onClick={(e) => {
																e.stopPropagation();
																removeFile(file.id);
															}}
														>
															{t('assets.uploader_remove')}
														</Button>
													) : file.status === 'uploading' ? (
														<div className="w-[120px] pr-2">
															<div className="flex justify-between items-center text-xs mb-1">
																<span className="truncate">{getStatusDisplay(file)}</span>
																<span className="flex-shrink-0 ml-1 tabular-nums">{file.progress}%</span>
															</div>
															<Progress
																value={file.progress}
																className={cn(
																	"h-1.5",
																	file.progress === 100 && "bg-green-200 dark:bg-green-900/30 [&>div]:bg-green-600 dark:[&>div]:bg-green-500"
																)}
															/>
														</div>
													) : (
														<Button
															variant="ghost"
															size="icon"
															className="h-7 w-7 rounded-full hover:bg-muted"
															onClick={(e) => {
																e.stopPropagation();
																removeFile(file.id);
															}}
														>
															<X className="h-4 w-4" />
															<span className="sr-only">Remove file</span>
														</Button>
													)}
												</div>
											</div>
										))}
									</div>
								</CardContent>
							</ScrollArea>
						</Card>
					</div>
				)}

				<DialogFooter className="gap-2 sm:gap-2 mt-4">
					<Button
						variant="outline"
						onClick={handleClose}
						disabled={uploading}
					>
						{allCompleted ? t('assets.uploader_close') : t('assets.uploader_cancel')}
					</Button>

					{!allCompleted && !uploading && (
						<Button
							onClick={uploadFiles}
							disabled={files.length === 0 || !files.some(file => file.status === 'pending')}
						>
							{t('assets.uploader_upload')}
						</Button>
					)}
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
} 