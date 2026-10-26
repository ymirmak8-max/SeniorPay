<div class="d-flex justify-content-end mt-3">
    <button type="button"
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#deleteParticipants">
        Delete Participants
    </button>
</div>

<!-- Modal -->
<div class="modal fade" id="deleteParticipants" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-headerbg-primary text-black ">
        <h1 class="modal-title fs-5" 
        id="exampleModalLabel">Delete Participant</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

      <form action="backend/participant_code.php" method="post">
        <input type="hidden" name="participant_id" value="<?php echo $participant->participant_id; ?>">
        <p>Are you sure you want to delete this participant?</p>
</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" name = "deleteParticipants" >Delete</button>
     
      </div>
</form>

    </div>
  </div>
</div>
