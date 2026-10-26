<div class="d-flex justify-content-end mt-3">
    <button type="button"
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#addParticipants">
        Add Participants
    </button>
</div>

<!-- Modal -->
<div class="modal fade" id="addParticipants" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-headerbg-primary text-black ">
        <h1 class="modal-title fs-5" 
        id="exampleModalLabel">Add Participant</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

      <form action="backend/participant_code.php" method="post">
        
        <div class="row g-3">
  <div class="col-12">
    <input type="text" class="form-control" placeholder="first_name" aria-label="first_name" name = "first_name">
  </div>
  <div class="col-12">
    <input type="text" class="form-control" placeholder="last_name" aria-label="last_name" name = "last_name">
  </div>
  <div class="col-12">
    <input type="text" class="form-control" placeholder="Email" aria-label="Email" name = "Email">
  </div>
  <div class="col-12">
    <select id="inputState" class="form-select" name = "gender">
      <option selected>Choose...</option>
      <option>Male</option>
       <option>Female</option>
    </select>
  </div>
  <div class="col-12">
    <input type="date" class="form-control" placeholder="birth_date" aria-label="birth_date" name = "birth_date">
  </div>
  <div class="col-12">
    <input type="text" class="form-control" placeholder="city" aria-label="city" name = "city">
  </div>
</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" name = "addParticipants" >Save changes</button>
     
      </div>
</form>

    </div>
  </div>
</div>
