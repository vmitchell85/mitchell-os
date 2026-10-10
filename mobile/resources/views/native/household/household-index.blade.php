<native:scroll-view fill>
    <native:column class="w-full gap-6 p-4">
        @if ($error !== '')
            <native:text class="text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if (! $loaded)
            <native:activity-indicator />
        @endif

        <native:column class="w-full gap-3">
            <native:text class="text-xl font-semibold text-theme-on-surface">Food rules</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">
                Every meal and food suggestion follows these.
            </native:text>
            <native:outlined-text-input
                ref="food-rules-input"
                label="Food rules"
                placeholder="No seafood"
                native:model="foodRules"
                multiline
                :min-lines="3"
                :max-lines="10"
                :max-length="2000"
            />
            <native:button
                ref="save-food-rules"
                label="Save food rules"
                :loading="$savingFoodRules"
                :disabled="$foodRules === $savedFoodRules"
                @press="saveFoodRules"
            />
            @if ($status !== '')
                <native:text class="text-sm text-theme-on-surface-variant">{{ $status }}</native:text>
            @endif
        </native:column>

        <native:column class="w-full gap-3">
            <native:text class="text-xl font-semibold text-theme-on-surface">Household notes</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">
                Things Claude should always keep in mind about the household.
            </native:text>

            @forelse ($notes as $note)
                <native:column class="w-full gap-2 p-4 bg-theme-surface rounded-xl">
                    @if ($editingNoteId === $note['id'])
                        <native:outlined-text-input
                            ref="edit-note-input"
                            label="Note"
                            native:model="editingBody"
                            multiline
                            :min-lines="2"
                            :max-lines="8"
                            :max-length="500"
                        />
                        <native:row class="gap-2">
                            <native:button ref="save-note" label="Save" size="sm" @press="saveNote" />
                            <native:button ref="cancel-edit" label="Cancel" size="sm" variant="ghost" @press="cancelEdit" />
                        </native:row>
                    @else
                        <native:text class="text-base text-theme-on-surface">{{ $note['body'] }}</native:text>
                        <native:row class="gap-2">
                            <native:button
                                ref="edit-note-{{ $note['id'] }}"
                                label="Edit"
                                size="sm"
                                variant="secondary"
                                @press="editNote({{ $note['id'] }})"
                            />
                            <native:button
                                ref="delete-note-{{ $note['id'] }}"
                                label="Delete"
                                size="sm"
                                variant="destructive"
                                @press="deleteNote({{ $note['id'] }})"
                            />
                        </native:row>
                    @endif
                </native:column>
            @empty
                @if ($loaded)
                    <native:text class="text-theme-on-surface-variant">No notes yet.</native:text>
                @endif
            @endforelse

            <native:outlined-text-input
                ref="new-note-input"
                label="New note"
                placeholder="Add something Claude should remember"
                native:model="newNote"
                multiline
                :min-lines="2"
                :max-lines="6"
                :max-length="500"
            />
            <native:button ref="add-note" label="Add note" icon="add" @press="addNote" />
        </native:column>
    </native:column>
</native:scroll-view>
