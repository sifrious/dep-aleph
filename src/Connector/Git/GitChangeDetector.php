<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

final class GitChangeDetector
{
    /**
     * @param  list<GitTreeEntry>  $previous
     * @param  list<GitTreeEntry>  $current
     * @return list<GitFileChange>
     */
    public function detect(array $previous, array $current): array
    {
        $before = $this->byPath($previous);
        $after = $this->byPath($current);
        $changes = [];
        $deleted = array_diff_key($before, $after);
        $added = array_diff_key($after, $before);

        foreach (array_intersect_key($after, $before) as $path => $entry) {
            if ($entry->blobSha !== $before[$path]->blobSha) {
                $changes[] = new GitFileChange(GitChangeKind::Modified, $path, null, $before[$path]->blobSha, $entry->blobSha);
            }
        }

        foreach ($deleted as $oldPath => $oldEntry) {
            $renamedPath = $this->pathForBlob($added, $oldEntry->blobSha);

            if ($renamedPath !== null) {
                $changes[] = new GitFileChange(GitChangeKind::Renamed, $renamedPath, $oldPath, $oldEntry->blobSha, $oldEntry->blobSha);
                unset($added[$renamedPath]);
            } else {
                $changes[] = new GitFileChange(GitChangeKind::Deleted, $oldPath, $oldPath, $oldEntry->blobSha, null);
            }
        }

        foreach ($added as $path => $entry) {
            $changes[] = new GitFileChange(GitChangeKind::Added, $path, null, null, $entry->blobSha);
        }

        usort($changes, static fn (GitFileChange $left, GitFileChange $right): int => [$left->path, $left->kind->value] <=> [$right->path, $right->kind->value]);

        return $changes;
    }

    /**
     * @param  list<GitTreeEntry>  $entries
     * @return array<string, GitTreeEntry>
     */
    private function byPath(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $indexed[$entry->path] = $entry;
        }

        ksort($indexed);

        return $indexed;
    }

    /**
     * @param  array<string, GitTreeEntry>  $entries
     */
    private function pathForBlob(array $entries, string $blobSha): ?string
    {
        foreach ($entries as $path => $entry) {
            if ($entry->blobSha === $blobSha) {
                return $path;
            }
        }

        return null;
    }
}
